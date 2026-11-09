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

    /**
     * A part stopped for these reasons still lets the pictures seen be described: the picture model failing on some, and
     * another scan holding the pictures (or the lock of one a deploy cut off), as `retrieval pictures` does.
     */
    public const DESCRIBE_ANYWAY = ['provider_error', 'already_running'];

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

        // Once every picture has its look, each gets its content: a part of descriptions at a time. The picture model
        // failing on some (a gallery it could not read) holds back none of the descriptions of those it saw: they come
        // now, and the rest wait for their own retry. It held a whole store's colours back, every few minutes again
        // (Take Me Home, 9.11: 89 pictures seen, 0 described).
        $stopped = $run->output['stopped'] ?? null;
        if ($stopped === null || in_array($stopped, self::DESCRIBE_ANYWAY, true)) {
            $run = $captions->captions($this->shopId);
        }

        if (in_array($run->output['stopped'] ?? null, self::GO_ON, true) && $this->part < self::MAX_PARTS) {
            self::dispatch($this->shopId, $this->part + 1);
        }
    }
}
