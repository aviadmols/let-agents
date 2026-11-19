<?php

namespace App\Modules\Enrichment\Contracts;

/**
 * Which kinds of a store's products go with which, and which products the store itself pairs: what the assistant and the
 * search read to answer "what else do I need with this?" (a drill: its bits and screws; a deck board: its screws and stain;
 * a dress: a belt, a bag). Two sources, both stored and read by code at answer time, never a model:
 *
 * - the store's goes-with map (BuildGoesWithMap): pairs of the store's own product types, written once per store by one
 *   model family and scored by the other, kept where both kinds hold products and the score reaches the threshold;
 * - the store's product relations (ComputeProductRelations, feature enrichment.relations): the merchant's cross-sells,
 *   what was bought together, the shop's rules, as `complement` rows.
 *
 * Call inside the shop's tenant.
 */
interface GoesWithPartners
{
    /** Whether the store has a goes-with map that passed. */
    public function hasMap(string $shopId): bool;

    /**
     * The kinds that go with a kind, best first, as the map has them.
     *
     * @param  string  $type  a product type key of the store's vocabulary
     * @return list<array{type: string, label: string, why: string, score: int}>
     */
    public function partnersOf(string $shopId, string $type): array;

    /**
     * The products the store pairs with a product (complement relations), best first; [] when the store keeps no relations.
     *
     * @return list<array{external_id: string, score: int, source: string}>
     */
    public function complementsOf(string $shopId, string $externalId): array;
}
