<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Contracts\PlatformStoreFeed;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;
use Generator;

/** The store feed of a connection, read by the reader registered for its platform. */
final class PlatformFeeds implements StoreFeed
{
    /** @param iterable<PlatformStoreFeed> $feeds */
    public function __construct(private readonly iterable $feeds) {}

    public function categories(StoreConnection $connection): array
    {
        return $this->for($connection)->categories($connection);
    }

    public function products(StoreConnection $connection): Generator
    {
        return $this->for($connection)->products($connection);
    }

    public function content(StoreConnection $connection, string $type): Generator
    {
        return $this->for($connection)->content($connection, $type);
    }

    public function contentTypes(StoreConnection $connection): array
    {
        return $this->for($connection)->contentTypes($connection);
    }

    private function for(StoreConnection $connection): PlatformStoreFeed
    {
        $platform = (string) ($connection->platform ?: 'woocommerce');

        foreach ($this->feeds as $feed) {
            if ($feed->platform() === $platform) {
                return $feed;
            }
        }

        throw StoreFeedUnavailable::unsupportedPlatform($platform);
    }
}
