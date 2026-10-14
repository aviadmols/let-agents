<?php

namespace App\Modules\Enrichment\Jobs;

use App\Modules\Enrichment\Actions\CompareReaders;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Two reading models on the same products, from the gaps screen, in the background. */
final class CompareReadersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly string $shopId)
    {
        $this->onQueue('long');
    }

    public function handle(CompareReaders $compare): void
    {
        $compare->handle($this->shopId);
    }
}
