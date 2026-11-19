<?php

namespace App\Modules\Enrichment\Contracts;

/**
 * The products the store itself pairs with a product: its cross-sells, what was bought together, the shop's rules, as
 * ComputeProductRelations keeps them (`complement` rows, feature enrichment.relations). Store data, read by code at
 * answer time when a shopper asks what goes with a product; never a model.
 *
 * Call inside the shop's tenant.
 */
interface GoesWithPartners
{
    /**
     * The products the store pairs with a product (complement relations), best first; [] when the store keeps no relations.
     *
     * @return list<array{external_id: string, score: int, source: string}>
     */
    public function complementsOf(string $shopId, string $externalId): array;
}
