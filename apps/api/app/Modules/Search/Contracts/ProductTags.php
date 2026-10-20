<?php

namespace App\Modules\Search\Contracts;

/**
 * The words of the tags a product was given by meaning (the Taxonomy module, where the shop has
 * tags in search on): what a shopper may type and find it by, though its title never says it
 * ("ספרי חטופים" finds Omer Shem Tov's book, tagged "חטופים ושבי"). Words a product's own text
 * holds are found as they always were; these only add. Empty where the shop has none.
 *
 * And the tags sure enough to narrow by (taxonomy.in_filters): each group a filter beside the results,
 * as a store's category is.
 *
 * The Taxonomy module binds it here (it requires this module, not the other way round). Call
 * inside the shop's tenant.
 */
interface ProductTags
{
    /** @return array<string, list<string>> by catalog product id */
    public function wordsByProduct(string $shopId): array;

    /**
     * The groups of tags shown as filters, each value with the catalog product ids it holds. Empty where
     * the shop has none.
     *
     * @return list<array{key: string, label: string, values: list<array{key: string, label: string, products: list<string>}>}>
     */
    public function filters(string $shopId, string $locale): array;
}
