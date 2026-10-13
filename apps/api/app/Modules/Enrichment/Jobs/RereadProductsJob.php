<?php

namespace App\Modules\Enrichment\Jobs;

use App\Modules\Enrichment\Actions\RereadProducts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** The products the gaps screen sent, read again by the model, in the background. */
final class RereadProductsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** @param  list<string>  $productIds */
    public function __construct(public readonly string $shopId, public readonly string $vocabularyId, public readonly array $productIds)
    {
        $this->onQueue('long');
    }

    public function handle(RereadProducts $reread): void
    {
        $reread->handle($this->shopId, $this->vocabularyId, $this->productIds);
    }
}
