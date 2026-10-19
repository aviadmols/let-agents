<?php

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Actions\ReadPageChoices;
use App\Modules\Runs\Enums\RunTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A shop's product pages read now, not a night's share at a time: a part of MINUTES at most (the
 * queue's retry_after stays above it, so no part is ever run twice at once), then the next part,
 * until no page is due. A shop of a few thousand pages is read in an hour or two, one page every
 * couple of seconds, as gently as the night reads it.
 */
final class ReadPageChoicesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One part's reading; the next part goes on from where it stopped. */
    public const MINUTES = 20;

    /** Parts in a row, at most: a shop of tens of thousands of pages finishes at night. */
    public const MAX_PARTS = 12;

    /** Seconds. The queue's retry_after (30 minutes) must stay above it. */
    public int $timeout = 1500;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public readonly string $shopId, public readonly int $part = 0)
    {
        $this->onQueue('long');
    }

    public function uniqueId(): string
    {
        return $this->shopId.':'.$this->part;
    }

    public function handle(ReadPageChoices $read): void
    {
        $read->handle($this->shopId, PHP_INT_MAX, RunTrigger::Manual, now()->addMinutes(self::MINUTES));

        if ($this->part + 1 < self::MAX_PARTS && $read->anyDue($this->shopId)) {
            self::dispatch($this->shopId, $this->part + 1);
        }
    }
}
