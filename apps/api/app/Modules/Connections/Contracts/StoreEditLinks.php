<?php

namespace App\Modules\Connections\Contracts;

use App\Modules\Connections\Models\StoreConnection;

/**
 * Where the store owner edits one of their pages or posts on their own site's admin (WordPress's editor, Shopify's
 * admin), so a passage the assistant answered from is fixed where it is written. Null when the platform has none.
 *
 * $type: page, post or policy (Catalog's content types); $externalId: the content's id on the store's site.
 */
interface StoreEditLinks
{
    public function editUrl(StoreConnection $connection, string $type, string $externalId): ?string;
}
