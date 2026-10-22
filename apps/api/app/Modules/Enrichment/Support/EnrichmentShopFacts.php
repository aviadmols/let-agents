<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Tenancy\Models\Shop;

/**
 * What the scan learned about the products, for the first-run screens: the approved details and tags, the
 * same kinds the scan's own step shows (EnrichmentScanDetails). Asked inside the shop.
 */
final class EnrichmentShopFacts implements ShopFacts
{
    private const KINDS = [FactKind::Spec, FactKind::Choice, FactKind::Flag, FactKind::Tag, FactKind::Use];

    public function shopFacts(Shop $shop): array
    {
        return [
            'tags' => EnrichmentFact::query()
                ->where('status', FactStatus::Approved)
                ->whereNotNull('product_id')
                ->whereIn('kind', array_map(fn (FactKind $k): string => $k->value, self::KINDS))
                ->count(),
        ];
    }
}
