<?php

namespace App\Modules\Assistant\Contracts;

/**
 * Which products a question names, as the store's search reads its words: the title, the
 * categories, tags, attributes and picture words, the store's synonyms, plurals and typos; never
 * by meaning alone, and never a model. "עגילים עד 500" names hoops titled "חישוקי זהב" that sit in
 * the earrings category, not a pendant that is merely close to earrings.
 *
 * The Search module reads it and binds it here (it requires this module, not the other way round).
 * Call inside the shop's tenant.
 */
interface ProductsNamed
{
    /**
     * @param  list<string>  $externalIds
     * @return list<string> those of them the words name; empty when none
     */
    public function among(string $shopId, string $question, array $externalIds): array;
}
