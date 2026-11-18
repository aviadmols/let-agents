<?php

namespace App\Modules\SearchSubscriptions\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\SearchSubscriptions\Mail\StillInterestedMail;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use App\Modules\SearchSubscriptions\Support\Availability;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * "Still interested?": once, search_subscriptions.remind_days_before days before a subscription ends (daily, from the
 * scheduler). Doing nothing lets it stop by itself.
 */
final class RemindEnding
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return int the reminders sent */
    public function handle(): int
    {
        $before = (int) Settings::get('search_subscriptions.remind_days_before');
        $keep = (int) Settings::get('search_subscriptions.keep_days');
        $due = $this->tenant->runUnscoped(fn () => SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->whereNull('reminded_at')
            ->whereNotNull('expires_at')->whereBetween('expires_at', [now(), now()->addDays($before)])->get());

        $sent = 0;
        foreach ($due as $subscription) {
            if (! Availability::on($subscription->shop_id)) {
                continue;
            }
            $shop = Shop::query()->find($subscription->shop_id);
            $this->tenant->run($subscription->shop_id, function () use ($subscription, $shop, $keep, &$sent): void {
                try {
                    Mail::to($subscription->email)->send(new StillInterestedMail($subscription, (string) ($shop?->name ?? ''), $keep));
                    $subscription->forceFill(['reminded_at' => now()])->save();
                    SearchSubscriptionSend::query()->create(['shop_id' => $subscription->shop_id, 'email_hash' => $subscription->email_hash, 'kind' => 'remind', 'queries' => [$subscription->query], 'products' => 0, 'sent_at' => now()]);
                    $sent++;
                } catch (Throwable $e) {
                    Log::warning('search subscriptions: a reminder failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
                }
            });
        }

        return $sent;
    }
}
