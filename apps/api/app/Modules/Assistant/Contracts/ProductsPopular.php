<?php

namespace App\Modules\Assistant\Contracts;

/**
 * How popular each product of a store is, as the store's own shoppers show it: what it sold (the store's count), and
 * what was opened and added to the cart from its search. "מה הספר הכי מומלץ" is answered from what its customers
 * choose, never from what a model thinks of the books. Read in code, never a model.
 *
 * The Search module reads it and binds it here (it requires this module, not the other way round).
 * Call inside the shop's tenant.
 */
interface ProductsPopular
{
    /**
     * @param  list<string>|null  $among  external ids; null: the whole store
     * @return array<string, float> external id => 0 to 1, the most popular first; only those with any sign of it
     */
    public function scores(string $shopId, ?array $among = null): array;
}
