<?php

namespace App\Modules\Connections\Contracts;

use App\Modules\Connections\Models\StoreConnection;
use Carbon\CarbonInterface;
use Generator;

/**
 * The products a store changed since a moment, so a new or edited product reaches the catalog and
 * the search within minutes instead of at night. A platform that cannot ask its store "what changed
 * since" answers null, and waits for the nightly read.
 *
 * Each page holds the records still on the store (the feed shape, as StoreFeed::products) and the
 * external IDs of products changed off it (archived, a draft, unpublished, deleted).
 *
 * Throws StoreFeedUnavailable when the store cannot be read.
 */
interface ReadsChangedProducts
{
    /** @return Generator<int, array{records: list<array<string, mixed>>, gone: list<string>}>|null */
    public function changedProducts(StoreConnection $connection, CarbonInterface $since): ?Generator;
}
