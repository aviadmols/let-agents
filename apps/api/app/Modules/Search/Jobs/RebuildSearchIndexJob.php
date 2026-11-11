<?php

namespace App\Modules\Search\Jobs;

use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\BuildSearchIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A shop's search index built again after its catalogue or its facts changed: one waiting build per shop. The
 * changes of the next minute (the ten-minute sync, the facts a review decided) join the build already waiting
 * instead of each building the whole index again (9-11.11: 302 builds in three days, up to three in one minute,
 * 43 seconds each). Unique until it starts: a change during a build waits for a build of its own, never lost.
 */
final class RebuildSearchIndexJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds a build waits for the changes that come with it. */
    public const GATHER_SECONDS = 60;

    /** A build is minutes at most; the lock never outlives a lost one by long. */
    public int $uniqueFor = 900;

    public int $timeout = 900;

    public function __construct(public readonly string $shopId)
    {
        $this->delay(now()->addSeconds(self::GATHER_SECONDS));
    }

    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(BuildSearchIndex $index): void
    {
        $index->handle($this->shopId, RunTrigger::System);
    }
}
