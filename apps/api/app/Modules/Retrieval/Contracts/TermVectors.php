<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * The words a shop says (its products' titles, tags and picture words), each embedded once, and the nearest of them to a
 * word a shopper typed that the shop does not say: "מפוספס" in a shop that says "פסים". The terms are embedded when the
 * search index is built, in batches, paid and counted; a typed word once, kept. Never on a keystroke: the search asks only
 * at a pause or on Enter. Call inside the shop's tenant.
 */
interface TermVectors
{
    /**
     * Embeds those of the terms not embedded yet. Returns how many were new.
     *
     * @param  list<string>  $terms
     */
    public function remember(string $shopId, array $terms): int;

    /**
     * The shop's terms nearest the word, at least $floor alike, best first.
     *
     * @return list<array{term: string, similarity: float}>
     */
    public function nearest(string $shopId, string $word, int $limit, float $floor): array;
}
