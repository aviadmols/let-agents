<?php

namespace App\Modules\Connections\Support;

use App\Modules\Connections\Contracts\PlatformEditLinks;
use App\Modules\Connections\Models\StoreConnection;

/** A WordPress site's editor for one page or post (its id is WordPress's own). */
final class PluginEditLinks implements PlatformEditLinks
{
    public function platform(): string
    {
        return 'woocommerce';
    }

    public function editUrl(StoreConnection $connection, string $type, string $externalId): ?string
    {
        $site = rtrim(trim((string) $connection->site_url), '/');

        if ($site === '' || preg_match('/^\d+$/', $externalId) !== 1) {
            return null;
        }

        return $site.'/wp-admin/post.php?post='.$externalId.'&action=edit';
    }
}
