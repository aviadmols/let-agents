<?php

namespace App\Modules\Search\Support;

use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Contracts\ProductsForQuery;

/** The store's search for some words, spelling only (no vector, not counted), products only. */
final class SpellingProductsForQuery implements ProductsForQuery
{
    public function __construct(private readonly SearchCatalog $search) {}

    public function find(string $shopId, string $query, int $limit): array
    {
        if ($limit < 1 || trim($query) === '') {
            return [];
        }

        $found = $this->search->handle($shopId, $query, ['product'], count: false, perGroup: $limit, meaning: false);

        return array_values(array_map(fn (array $p): string => (string) $p['external_id'], $found['groups']['product']));
    }
}
