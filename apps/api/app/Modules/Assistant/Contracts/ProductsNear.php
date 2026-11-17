<?php

namespace App\Modules\Assistant\Contracts;

/**
 * The products nearest a question as the store's own search finds them, by its words and by meaning together: what a
 * shopper who typed the question into the search box would be shown first. No model is asked beyond the search's own
 * stored vectors.
 *
 * A question's products were only those its words found while it was typed, and the pieces of pages nearest it: "מה מתאים
 * לטיפול בציפורים?" was given two impact adapters ("מתאים", "מתאמים") while the search by meaning put the pigeon spikes
 * first (Gueta, 17.11).
 *
 * The Search module reads it and binds it here (it requires this module, not the other way round).
 */
interface ProductsNear
{
    /** @return list<string> external ids, nearest first; empty when the search found nothing as asked */
    public function near(string $shopId, string $question, int $limit): array;
}
