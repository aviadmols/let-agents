<?php

namespace App\Modules\Enrichment\Listeners;

use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Enrichment\Actions\BuildCards;
use App\Modules\Runs\Enums\RunTrigger;

/**
 * A sync's products get their cards at once, on the queue: a new product is read by every model
 * minutes after the store publishes it. A full sync cards the whole shop (pages and the store too).
 * Code only, no model.
 */
final class RebuildCards
{
    public function handle(CatalogUpdated|CatalogProductsChanged $event): void
    {
        $shopId = $event->shopId;
        $ids = $event instanceof CatalogProductsChanged ? array_values(array_map('strval', $event->externalIds)) : null;

        dispatch(function () use ($shopId, $ids): void {
            app(BuildCards::class)->handle($shopId, $ids, RunTrigger::System);
        });
    }
}
