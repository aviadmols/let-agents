<?php

namespace App\Modules\Taxonomy\Listeners;

use App\Core\Facades\Features;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Taxonomy\Jobs\TagChangesJob;

/**
 * A shop that tags by itself (taxonomy.tagging) tags what it changed: the products a sync read between nights,
 * within minutes; after a whole read of the store (the night's sync, "scan now", a post the platform published),
 * every product and post whose words changed. Code only here: the job decides whether anything waits.
 */
final class TagWhatChanged
{
    /** Minutes after a whole read, for what follows it (the store's pages, the scan) to land first. */
    private const AFTER_READ = 20;

    public function handle(CatalogUpdated|CatalogProductsChanged $event): void
    {
        if (! Features::enabled('taxonomy.tagging', $event->shopId)) {
            return;
        }

        if ($event instanceof CatalogProductsChanged) {
            TagChangesJob::products($event->shopId, $event->externalIds);

            return;
        }

        TagChangesJob::dispatch($event->shopId, true)->delay(now()->addMinutes(self::AFTER_READ));
    }
}
