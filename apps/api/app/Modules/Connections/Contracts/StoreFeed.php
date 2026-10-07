<?php

namespace App\Modules\Connections\Contracts;

use App\Modules\Connections\Models\StoreConnection;
use Generator;

/**
 * Reads a connected store's catalog in the feed shape, whatever the platform.
 *
 * WooCommerce reads it from the Let Agents plugin today. A Shopify reader implements the same
 * contract later, and nothing that consumes the feed changes.
 *
 * Every method throws StoreFeedUnavailable when the store cannot be read.
 */
interface StoreFeed
{
    /** @return list<array<string, mixed>> every product category, parents included */
    public function categories(StoreConnection $connection): array;

    /**
     * Published products, one page of records at a time, in a stable order.
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function products(StoreConnection $connection): Generator;

    /**
     * Published content of one type (for WooCommerce, a post type the store shares).
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function content(StoreConnection $connection, string $type): Generator;

    /** @return list<string> the content types the store chose to share */
    public function contentTypes(StoreConnection $connection): array;
}
