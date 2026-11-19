<?php

namespace App\Modules\Enrichment\Contracts;

/**
 * What each product of the store is, as its approved type fact says, and which of the store's types a name the store
 * files products under says. Read by the search index: a category or a tag that names a type other than the product's
 * own is none of the product's words (a mesh top tagged "T-shirts" is not found for "T-shirt": Vans, 19.11).
 *
 * Bound by this module. Call inside the shop's tenant.
 */
interface ProductTypes
{
    /**
     * The products whose type is settled, by product id: the key of the approved type, or null for a product the type
     * audit found to be of no type of the bank (its filing's types were taken off). A product not listed has no settled
     * type, and nothing changes for it.
     *
     * @return array<string, string|null>
     */
    public function settled(): array;

    /**
     * Of the given names, the ones that say exactly one of the store's types, as code reads them (whole words, the letters
     * Hebrew joins before a word and the usual endings allowed): the type key by name. A name that says two types ("Tees
     * & Shirts") or none is left out.
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function namedBy(array $names): array;
}
