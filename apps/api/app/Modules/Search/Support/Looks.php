<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Settings;

/**
 * Whether how a product looks matters in the shop's search, as the shop chose (search.similar_by): by picture or both,
 * it does; by meaning alone (a store of supplements, of books), it does not. Where it does not, no photo search is
 * offered and no picture finds a product for words: a supplement's bottle is no answer to "קשב וריכוז".
 */
final class Looks
{
    public static function matter(string $shopId): bool
    {
        return (string) Settings::get('search.similar_by', $shopId) !== 'meaning';
    }
}
