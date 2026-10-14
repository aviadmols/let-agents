<?php

namespace App\Modules\TryOn\Jobs;

use App\Modules\TryOn\Actions\RunTryOn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One try, made in the background: a picture takes a minute or two. */
final class RunTryOnJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly string $shopId, public readonly string $jobId)
    {
        $this->onQueue('long');
    }

    public function handle(RunTryOn $run): void
    {
        $run->handle($this->shopId, $this->jobId);
    }
}
