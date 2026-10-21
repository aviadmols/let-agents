<?php

namespace App\Modules\Connections\Contracts;

use App\Modules\Connections\Models\StoreConnection;
use Generator;

/**
 * What of a store's products can be bought now, and nothing else: a read light enough for several
 * times a day (Catalog's SyncStock), where a stock change on Shopify (its inventory, kept apart from
 * the product) never marks the product changed. A platform that cannot answer so lightly answers
 * null, and its stock comes with the products it reads.
 *
 * Each page: by product external id, whether it can be bought and, by variant external id, whether
 * each variant read can be.
 *
 * Throws StoreFeedUnavailable when the store cannot be read.
 */
interface ReadsStock
{
    /** @return Generator<int, array<string, array{in_stock: bool, variants: array<string, bool>}>>|null */
    public function stock(StoreConnection $connection): ?Generator;
}
