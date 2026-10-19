<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Enrichment\Events\FactsDecided;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\BuildSearchIndex;

/**
 * The search box searches today's catalogue: a sync, a move to a new address, or facts the scan
 * wrote or a review decided (what a product is and is for, its filters) rebuild it now,
 * on the queue, instead of at night. Code only, no model.
 */
final class RebuildSearchIndex
{
    public function handle(CatalogUpdated|CatalogProductsChanged|FactsDecided $event): void
    {
        $shopId = $event->shopId;

        dispatch(function () use ($shopId): void {
            app(BuildSearchIndex::class)->handle($shopId, RunTrigger::System);
        });
    }
}
