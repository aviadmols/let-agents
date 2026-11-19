<?php

namespace App\Modules\Enrichment\Support;

use App\Core\Facades\Features;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Contracts\GoesWithPartners;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentGoesWithMap;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;

/**
 * What the store keeps of which kinds and which products go together (GoesWithPartners): the latest goes-with map that
 * passed, and the complement relations where the shop still has them. Reads only; never a model.
 */
final class StoredGoesWithPartners implements GoesWithPartners
{
    public function hasMap(string $shopId): bool
    {
        return $this->map($shopId) !== null;
    }

    public function partnersOf(string $shopId, string $type): array
    {
        $map = $this->map($shopId);
        if ($map === null) {
            return [];
        }
        $labels = [];
        foreach ((array) $map->types as $t) {
            $labels[(string) $t['key']] = (string) ($t['label'] ?? $t['key']);
        }
        $out = [];
        foreach ((array) $map->pairs as $pair) {
            if ((string) $pair['type'] === $type) {
                $partner = (string) $pair['partner_type'];
                $out[] = ['type' => $partner, 'label' => $labels[$partner] ?? $partner, 'why' => (string) ($pair['why'] ?? ''), 'score' => (int) ($pair['score'] ?? 0)];
            }
        }
        usort($out, fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['type'], $b['type']));

        return $out;
    }

    public function complementsOf(string $shopId, string $externalId): array
    {
        if (! Features::enabled('enrichment.relations', $shopId)) {
            return [];
        }
        $productId = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->value('id');
        if ($productId === null) {
            return [];
        }
        $rows = EnrichmentProductRelation::query()->where('product_id', $productId)->where('kind', RelationKind::Complement->value)
            ->orderByDesc('score')->get(['related_product_id', 'score', 'source']);
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = CatalogProduct::query()->whereNull('removed_at')->whereIn('id', $rows->pluck('related_product_id'))->pluck('external_id', 'id');

        return array_values(array_filter(array_map(fn (EnrichmentProductRelation $r): ?array => isset($ids[$r->related_product_id])
            ? ['external_id' => (string) $ids[$r->related_product_id], 'score' => (int) $r->score, 'source' => (string) $r->source]
            : null, $rows->all())));
    }

    private function map(string $shopId): ?EnrichmentGoesWithMap
    {
        return EnrichmentGoesWithMap::query()->where('shop_id', $shopId)->where('status', EnrichmentGoesWithMap::PASSED)->latest()->first();
    }
}
