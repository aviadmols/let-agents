<?php

namespace App\Modules\Catalog\Events;

/**
 * Someone asked to read a shop's store again now (a button on another module's screen). The
 * Catalog module queues its own sync job, with the time a large store needs, so a module that
 * only reads the catalogue never has to know how the sync runs.
 */
final class CatalogSyncRequested
{
    public function __construct(public readonly string $shopId) {}
}
