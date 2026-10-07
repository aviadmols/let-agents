<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * The pieces of the store's own text nearest to a question, with the text itself: what an answer
 * may be written from and must cite. One embedding per new wording, kept, like nearText.
 *
 * Call inside the shop's tenant.
 */
interface Passages
{
    /**
     * @param  list<string>  $sources  product, content, ...
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, text: string, similarity: float}> nearest first, at most two pieces from one document
     */
    public function near(string $shopId, string $text, array $sources, int $limit): array;
}
