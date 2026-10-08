<?php

namespace App\Modules\Search\Listeners;

use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Search\Actions\BuildSearchIndex;

/**
 * The search box searches today's catalogue: a sync or a move to a new address rebuilds it now,
 * on the queue, instead of at night. Code only, no model.
 */
final class RebuildSearchIndex
{
    public function handle(CatalogUpdated $event): void
    {
        $shopId = $event->shopId;

        dispatch(function () use ($shopId): void {
            app(BuildSearchIndex::class)->handle($shopId, RunTrigger::System);
        });
    }
}
