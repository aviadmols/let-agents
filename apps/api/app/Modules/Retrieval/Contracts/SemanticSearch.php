<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * Finding by meaning in a shop's index, without a model call: it compares vectors the index
 * already holds. Callers run inside the shop's tenant context.
 */
interface SemanticSearch
{
    /**
     * What is nearest to one indexed thing, among the sources asked for.
     *
     * @param  list<string>  $sources  e.g. ['product'] or ['content']
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, similarity: float}> nearest first, one per source document
     */
    public function similarTo(string $source, string $sourceId, array $sources, int $limit): array;

    /**
     * What is nearest in meaning to words a shopper typed. Unlike similarTo, a new wording costs
     * one small embedding call: it asks SpendGuard first and counts against the monthly cap. The
     * vector is kept per shop and model, so the same words are free the next time. Empty when
     * there is no key, no budget or no vectors yet; callers then search by spelling alone.
     *
     * @param  list<string>  $sources
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, similarity: float}> nearest first, one per source document
     */
    public function nearText(string $shopId, string $text, array $sources, int $limit): array;

    /**
     * Products whose main picture looks like this product's. No model call: it compares the
     * picture vectors the image index already holds. Empty when pictures are not indexed.
     *
     * @return list<array{product_id: string, external_id: string, title: string, similarity: float}> nearest first
     */
    public function lookAlike(string $productId, int $limit): array;

    /**
     * Products whose picture matches words a shopper typed ("חולצת פסים"). The words cost one
     * small embedding the first time and nothing after, like nearText.
     *
     * @return list<array{product_id: string, external_id: string, title: string, similarity: float}> nearest first
     */
    public function picturesNearText(string $shopId, string $text, int $limit): array;

    /** Whether this shop has picture vectors from the model the settings name now. */
    public function picturesReady(): bool;

    /** Whether this shop has vectors from the model the settings name now. */
    public function ready(): bool;
}
