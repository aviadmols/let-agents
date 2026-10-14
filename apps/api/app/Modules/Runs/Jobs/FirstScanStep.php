<?php

namespace App\Modules\Runs\Jobs;

use App\Modules\Runs\Support\FirstScan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * One step of a shop's scan, started from its overview: the same command the night runs, for this
 * shop now, with the step marked before and after so the screen can say where the scan is.
 */
final class FirstScanStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** The longest step (pictures of a large catalogue) runs past the default. */
    public int $timeout = 3600;

    /** A step that failed is not repeated: the night runs it again anyway. */
    public int $tries = 1;

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
        $code = Artisan::call($this->command, $this->parameters);
        FirstScan::mark($this->shopId, $this->step, $code === 0 ? FirstScan::DONE : FirstScan::FAILED);
    }

    public function failed(): void
    {
        FirstScan::mark($this->shopId, $this->step, FirstScan::FAILED);
    }
}
