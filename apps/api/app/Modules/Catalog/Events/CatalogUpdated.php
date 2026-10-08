<?php

namespace App\Modules\Catalog\Events;

/** A shop's catalogue changed now (a sync, or links moved to a new address), not at night. */
final class CatalogUpdated
{
    public function __construct(public readonly string $shopId) {}
}
