<?php

namespace App\Modules\Search\Contracts;

/**
 * Products like one product by the tags by meaning they share, weighed by how central each is, how far it is trusted
 * and how rare it is in the store (Taxonomy's Related): two memoirs of captivity are alike, a horror classic is not,
 * however near their descriptions read by meaning. Empty where the product has no tags, or the shop has no tags in
 * search. The Taxonomy module binds it here.
 */
interface RelatedByTags
{
    /** @return list<array{external_id: string, score: float}> the likest first */
    public function to(string $shopId, string $externalId, int $limit): array;

    /**
     * The team's own word on one product, by a rule it wrote and approved: the products it is kept apart from (never
     * shown as alike) and put together with (always alike, first). Empty where the team said nothing.
     *
     * @return array{apart: list<string>, alike: list<string>} external ids
     */
    public function pairs(string $shopId, string $externalId): array;
}
