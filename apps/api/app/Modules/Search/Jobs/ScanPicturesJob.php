<?php

namespace App\Modules\Search\Jobs;

use App\Modules\Retrieval\Contracts\CaptionsPictures;
use App\Modules\Retrieval\Contracts\RunsRetrieval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A shop's picture scan, pressed from the screen. A scan stops itself before the worker's time
 * runs out; what is left goes on in the next part, until every picture is done.
 */
final class ScanPicturesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Seconds. Above the scan's own time budget, below the queue's retry_after. */
    public int $timeout = 1500;

    /** A failed part is a failed run on the screen; the next press or the night goes on. */
    public int $tries = 1;

    private const MAX_PARTS = 1000;

    /** A part stopped for these reasons leaves work for the next one. */
    public const GO_ON = ['more', 'time_budget'];

    public function __construct(
        public readonly string $shopId,
        public readonly int $part = 1,
    ) {
        $this->onQueue('long');
    }

    /** One waiting scan per shop: pressing twice, or a part and a press, queue it once. */
    public function uniqueId(): string
    {
        return $this->shopId;
    }

    public function handle(RunsRetrieval $retrieval, CaptionsPictures $captions): void
    {
        $run = $retrieval->images($this->shopId);

        // Once every picture has its look, each gets its content: a part of descriptions at a time.
        if (! in_array($run->output['stopped'] ?? null, self::GO_ON, true) && ($run->output['stopped'] ?? null) === null) {
            $run = $captions->captions($this->shopId);
        }

        if (in_array($run->output['stopped'] ?? null, self::GO_ON, true) && $this->part < self::MAX_PARTS) {
            self::dispatch($this->shopId, $this->part + 1);
        }
    }
}
