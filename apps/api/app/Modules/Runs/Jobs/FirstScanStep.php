<?php

namespace App\Modules\Runs\Jobs;

use App\Modules\Runs\Support\FirstScan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * One step of a shop's scan, started from its overview: the same command the night runs, for this
 * shop now, with the step marked before and after so the screen can say where the scan is.
 */
final class FirstScanStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Below the queue's retry_after (30 minutes): a step still running then would be handed to a
     * second worker while the first goes on. The long steps keep within it (pictures) or go on as
     * jobs of their own (the product pages).
     */
    public int $timeout = 1700;

    /** Once more when a restart (a deploy) cut it off: each step reads only what changed, so again is safe. */
    public int $tries = 2;

    /** @param array<string, string|bool> $parameters */
    public function __construct(
        public readonly string $shopId,
        public readonly string $step,
        public readonly string $command,
        public readonly array $parameters,
    ) {}

    public function handle(): void
    {
        FirstScan::mark($this->shopId, $this->step, FirstScan::RUNNING);

        try {
            $code = Artisan::call($this->command, $this->parameters);
        } catch (Throwable $e) {
            // One step's trouble is that step's: marked failed, and the scan goes on to the next.
            report($e);
            $code = 1;
        }

        FirstScan::mark($this->shopId, $this->step, $code === 0 ? FirstScan::DONE : FirstScan::FAILED);
    }

    public function failed(): void
    {
        FirstScan::mark($this->shopId, $this->step, FirstScan::FAILED);
    }
}
