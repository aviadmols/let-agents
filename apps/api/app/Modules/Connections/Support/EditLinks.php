<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Contracts\PlatformEditLinks;
use App\Modules\Connections\Contracts\StoreEditLinks;
use App\Modules\Connections\Models\StoreConnection;

/** A connection's edit links, by the platform registered for it; none for a platform with no registry entry. */
final class EditLinks implements StoreEditLinks
{
    /** @param iterable<PlatformEditLinks> $links */
    public function __construct(private readonly iterable $links) {}

    public function editUrl(StoreConnection $connection, string $type, string $externalId): ?string
    {
        $platform = (string) ($connection->platform ?: 'woocommerce');

        foreach ($this->links as $links) {
            if ($links->platform() === $platform) {
                return $links->editUrl($connection, $type, $externalId);
            }
        }

        return null;
    }
}
