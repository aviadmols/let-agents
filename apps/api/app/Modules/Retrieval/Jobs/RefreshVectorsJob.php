<?php

namespace App\Modules\Retrieval\Jobs;

use App\Core\Facades\Features;
use App\Modules\Retrieval\Actions\BuildIndex;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * A shop's meaning vectors, brought up to date after its catalogue changed, without waiting for the
 * night. One at a time a shop, a few minutes after the change, so a burst of edits is one run; the
 * index reads everything again in code and embeds only the pieces whose text changed (BuildIndex),
 * each batch asking SpendGuard first. A run that embedded all one run may and left pieces waiting
 * (a whole catalogue read again) is followed by the next at once, until none waits; a run stopped
 * by the spending cap or a provider error is not. The nightly build stays: what these missed, it finishes.
 */
final class RefreshVectorsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Runs in a row after one change, at most. */
    public const MAX_ROUNDS = 40;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Changes within this many seconds of the first wait for the same run. */
    public int $uniqueFor = 900;

    public function __construct(public readonly string $shopId, public readonly int $round = 0) {}

    public function uniqueId(): string
    {
        return $this->round === 0 ? $this->shopId : $this->shopId.':'.$this->round;
    }

    /** @return list<object> never two runs of one shop at once: the next waits a minute and tries again */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('retrieval-refresh:'.$this->shopId))->releaseAfter(60)->expireAfter(900)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(BuildIndex $index): void
    {
        if (! Features::enabled('retrieval.index', $this->shopId) || ! Features::enabled('retrieval.refresh_on_change', $this->shopId)) {
            return;
        }

        $embedding = (array) (((array) $index->handle($this->shopId)->output)['embedding'] ?? []);

        if (self::more($embedding) && $this->round < self::MAX_ROUNDS) {
            self::dispatch($this->shopId, $this->round + 1);
        }
    }

    /** @param  array<string, mixed>  $embedding  BuildIndex's output: pieces still waiting, though this run went well */
    public static function more(array $embedding): bool
    {
        return (int) ($embedding['pending'] ?? 0) > 0 && (int) ($embedding['embedded'] ?? 0) > 0 && ($embedding['stopped'] ?? null) === null;
    }
}
