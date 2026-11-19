<?php

namespace App\Modules\Enrichment\Listeners;

use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Enrichment\Jobs\ReadNewProductsJob;

/**
 * After a sync, the products code has never read are read at once, on the queue (ReadNewProductsJob): one waiting job per
 * shop, whatever the syncs of the minute. Code only, no model.
 */
final class ReadNewProducts
{
    public function handle(CatalogUpdated|CatalogProductsChanged $event): void
    {
        ReadNewProductsJob::dispatch($event->shopId);
    }
}
