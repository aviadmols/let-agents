<?php

namespace App\Modules\Retrieval\Listeners;

use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Retrieval\Contracts\RefreshesVectors;

/**
 * A product or post the store changed today is found by its new words today: a sync queues one
 * refresh of the shop's vectors, a few minutes later, so the edits of those minutes go together.
 */
final class RefreshVectors
{
    public function __construct(private readonly RefreshesVectors $vectors) {}

    public function handle(CatalogUpdated|CatalogProductsChanged $event): void
    {
        $this->vectors->soon($event->shopId);
    }
}
