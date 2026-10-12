<?php

namespace App\Modules\Search\Contracts;

/**
 * The products the store's own search finds for some words, by spelling only: no vector is made
 * and nothing is counted, so a page can ask on load. For "more like what you searched for" on a
 * product page. Call inside the shop's tenant.
 */
interface ProductsForQuery
{
    /** @return list<string> external ids, best first */
    public function find(string $shopId, string $query, int $limit): array;
}
