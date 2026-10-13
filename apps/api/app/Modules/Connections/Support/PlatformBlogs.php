<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Contracts\PlatformStoreBlog;
use App\Modules\Connections\Contracts\StoreBlog;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Connections\Models\StoreConnection;

/** A connection's blog, written by the publisher registered for its platform. */
final class PlatformBlogs implements StoreBlog
{
    /** @param iterable<PlatformStoreBlog> $blogs */
    public function __construct(private readonly iterable $blogs) {}

    public function push(StoreConnection $connection, array $post, ?string $remoteId): array
    {
        $platform = (string) ($connection->platform ?: 'woocommerce');

        foreach ($this->blogs as $blog) {
            if ($blog->platform() === $platform) {
                return $blog->push($connection, $post, $remoteId);
            }
        }

        throw StoreFeedUnavailable::unsupportedPlatform($platform);
    }
}
