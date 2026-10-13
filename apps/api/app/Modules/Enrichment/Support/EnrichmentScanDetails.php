<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Admin\Contracts\ScanDetails;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Tenancy\Models\Shop;

/**
 * The step that understands the products: for each product type, the attributes and tags learned,
 * how many products have each and a few of their values, as the shop manager reads them.
 */
final class EnrichmentScanDetails implements ScanDetails
{
    /** Enough to show the picture, bounded on a large catalogue. */
    private const FACTS = 6000;

    private const TYPES = 8;

    private const PER_TYPE = 10;

    private const EXAMPLES = 3;

    private const KINDS = [FactKind::Spec, FactKind::Choice, FactKind::Flag, FactKind::Tag, FactKind::Use];

    public function scanDetails(Shop $shop): array
    {
        $facts = EnrichmentFact::query()
            ->with('vocabulary')
            ->where('status', FactStatus::Approved)
            ->whereNotNull('product_id')
            ->whereIn('kind', array_map(fn (FactKind $k): string => $k->value, self::KINDS))
            ->latest('updated_at')
            ->limit(self::FACTS)
            ->get();

        if ($facts->isEmpty()) {
            return ['products' => [['title' => __('enrichment::scan.nothing_yet'), 'rows' => []]]];
        }

        $types = CatalogProduct::query()->whereIn('id', $facts->pluck('product_id')->unique())->pluck('payload', 'id')
            ->map(fn ($payload): string => trim((string) (((array) $payload)['product_type'] ?? '')) ?: __('enrichment::scan.no_type'));

        // type => attribute label => [product ids, values]
        $learned = [];
        foreach ($facts as $fact) {
            $type = $types[$fact->product_id] ?? __('enrichment::scan.no_type');
            $key = $fact->kind === FactKind::Tag ? __('enrichment::scan.tags') : $fact->keyLabel();
            $learned[$type][$key]['products'][$fact->product_id] = true;
            $value = trim($fact->valueLabel());
            if ($value !== '') {
                $learned[$type][$key]['values'][$value] = ($learned[$type][$key]['values'][$value] ?? 0) + 1;
            }
        }

        uasort($learned, fn (array $a, array $b): int => array_sum(array_map(fn ($x) => count($x['products']), $b)) <=> array_sum(array_map(fn ($x) => count($x['products']), $a)));

        $sections = [];
        foreach (array_slice($learned, 0, self::TYPES, true) as $type => $attributes) {
            uasort($attributes, fn (array $a, array $b): int => count($b['products']) <=> count($a['products']));
            $rows = [];
            foreach (array_slice($attributes, 0, self::PER_TYPE, true) as $label => $attribute) {
                $values = $attribute['values'] ?? [];
                arsort($values);
                $rows[] = [(string) $label, __('enrichment::scan.attribute', [
                    'count' => count($attribute['products']),
                    'values' => implode(', ', array_slice(array_keys($values), 0, self::EXAMPLES)),
                ])];
            }
            $sections[] = ['title' => (string) $type, 'rows' => $rows];
        }

        return ['products' => $sections];
    }
}
