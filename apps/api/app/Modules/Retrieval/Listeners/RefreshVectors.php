<?php

namespace App\Modules\Retrieval\Listeners;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Retrieval\Jobs\RefreshVectorsJob;

/**
 * A product or post the store changed today is found by its new words today: a sync queues one
 * refresh of the shop's vectors, a few minutes later, so the edits of those minutes go together.
 */
final class RefreshVectors
{
    public function handle(CatalogUpdated|CatalogProductsChanged $event): void
    {
        if (! Features::enabled('retrieval.index', $event->shopId) || ! Features::enabled('retrieval.refresh_on_change', $event->shopId)) {
            return;
        }

        RefreshVectorsJob::dispatch($event->shopId)->delay(now()->addMinutes((int) Settings::get('retrieval.refresh_after_minutes', $event->shopId)));
    }
}
