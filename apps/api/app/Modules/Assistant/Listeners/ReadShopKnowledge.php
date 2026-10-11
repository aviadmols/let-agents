<?php

namespace App\Modules\Assistant\Listeners;

use App\Modules\Assistant\Jobs\BuildShopKnowledgeJob;
use App\Modules\Catalog\Events\CatalogUpdated;

/** A sync brought the store's pages: what they repeat is read again, the first sync included. */
final class ReadShopKnowledge
{
    public function handle(CatalogUpdated $event): void
    {
        BuildShopKnowledgeJob::dispatch($event->shopId);
    }
}
