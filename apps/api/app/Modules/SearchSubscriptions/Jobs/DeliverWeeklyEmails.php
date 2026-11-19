<?php

namespace App\Modules\SearchSubscriptions\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Modules\SearchSubscriptions\Support\MailServiceBusy;
use App\Modules\SearchSubscriptions\Support\WeeklyEmails;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mails up to SendWeekly::BATCH weekly emails of one store (WeeklyEmails). At most the pace the mail service allows
 * (the 'search-subscriptions-mail' limiter, search_subscriptions.mail_requests_per_second): a job over it waits its
 * turn, for hours if it must, without counting as a failure. An email marked mailed is never mailed again, so a retry
 * sends only what did not go. Three real failures in a row: the emails are taken back, and their products wait for the
 * next week.
 */
final class DeliverWeeklyEmails implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 180;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    /** @param  list<string>  $sendIds */
    public function __construct(public readonly string $shopId, public readonly array $sendIds) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('search-subscriptions-mail')];
    }

    /** Waiting for its turn is not a failure: it may wait this long. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(TenantContext $tenant, WeeklyEmails $emails): void
    {
        try {
            $tenant->run($this->shopId, fn (): int => $emails->deliver($this->shopId, $this->sendIds));
        } catch (MailServiceBusy $busy) {
            $this->release($busy->retryAfter);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('search subscriptions: weekly emails could not be mailed', ['shop' => $this->shopId, 'emails' => count($this->sendIds), 'error' => $e?->getMessage()]);
        app(TenantContext::class)->run($this->shopId, fn () => WeeklyEmails::takeBack($this->sendIds));
    }
}
