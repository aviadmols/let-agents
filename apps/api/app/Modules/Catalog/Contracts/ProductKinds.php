<?php

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\CatalogProduct;

/**
 * What kind of product one is, from what the store itself says: its product type, else the
 * categories it is listed under, else the tags only some of its products carry ("חיתולי במבוק",
 * not "כל המוצרים"). Read in code, no model. A product of no known kind has no marks.
 */
interface ProductKinds
{
    /** @return list<string> its kind, as marks ("type:…", "category:…", "tag:…"); empty when unknown */
    public function marks(CatalogProduct $product): array;

    /**
     * The products among these of its own kind, in the order given. A product of no known kind
     * keeps them all.
     *
     * @param  list<string>  $externalIds
     * @return list<string>
     */
    public function sameKind(CatalogProduct $product, array $externalIds): array;

    /** Whether the store has another active product of its kind (false when its kind is unknown). */
    public function hasLike(CatalogProduct $product): bool;

    /**
     * A print, collection or series it shares with other products, as the store tags it
     * ("אייקוני - כדורים פורחים"); null when none.
     *
     * @return array{tag: string, count: int}|null count: the other products that carry it
     */
    public function series(CatalogProduct $product): ?array;
}
