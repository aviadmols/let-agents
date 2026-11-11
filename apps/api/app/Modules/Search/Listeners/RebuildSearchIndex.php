<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Enrichment\Events\FactsDecided;
use App\Modules\Search\Jobs\RebuildSearchIndexJob;

/**
 * The search box searches today's catalogue: a sync, a move to a new address, or facts the scan
 * wrote or a review decided (what a product is and is for, its filters) rebuild it on the queue,
 * instead of at night: one build per shop for the changes of a minute (RebuildSearchIndexJob).
 * Code only, no model.
 */
final class RebuildSearchIndex
{
    public function handle(CatalogUpdated|CatalogProductsChanged|FactsDecided $event): void
    {
        RebuildSearchIndexJob::dispatch($event->shopId);
    }
}
