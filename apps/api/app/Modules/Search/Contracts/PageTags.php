<?php

namespace App\Modules\Search\Contracts;

/**
 * The tags a page offers a shopper, each with what it finds right now.
 *
 * Written and checked at night by the Search module; the results are searched when asked, with
 * the store's own search, so they follow stock and new products. Call inside the shop's tenant.
 */
interface PageTags
{
    /**
     * @param  string  $type  product or content
     * @return list<array{label: string, query: string, products: list<string>, content: list<string>}> external ids, best first; the page itself is never among them
     */
    public function forPage(string $shopId, string $type, string $externalId, int $perTag): array;
}
