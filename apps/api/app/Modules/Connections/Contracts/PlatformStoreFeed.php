<?php

namespace App\Modules\Connections\Contracts;

/**
 * One platform's way of reading a store's catalog in the feed shape. Registered with the tag
 * "connections.store_feeds"; StoreFeed hands each connection to the one for its platform. A new
 * platform is one class and one tag.
 */
interface PlatformStoreFeed extends StoreFeed
{
    /** The platform value on StoreConnection this reader serves: "woocommerce", "shopify". */
    public function platform(): string;
}
