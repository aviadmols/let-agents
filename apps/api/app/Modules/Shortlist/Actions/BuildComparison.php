<?php

namespace App\Modules\Shortlist\Actions;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;

/**
 * The comparison table of a few products, from the shop's approved facts: what each product is,
 * its maker, its choices (metal, karat), its measures and its yes/no specs, one row each, in the
 * shopper's language. Code only: no model is asked. A row no product has is left out; a row whose
 * values are not all the same is marked, so the shopper sees at once where they differ.
 * Run inside the shop's tenant context.
 */
final class BuildComparison
{
    /** The rows' order: what it is first, then the maker, its choices, its measures and its specs. */
    private const KINDS = [FactKind::Type, FactKind::Brand, FactKind::Choice, FactKind::Spec, FactKind::Flag];

    public function __construct(private readonly ProductCards $cards) {}

    /**
     * @param  list<string>  $externalIds
     * @return array{products: list<array<string, mixed>>, rows: list<array{key: string, label: string, values: list<string|null>, differs: bool}>}
     */
    public function handle(array $externalIds, string $locale): array
    {
        $before = app()->getLocale();
        app()->setLocale($locale);

        try {
            $products = $this->cards->handle($externalIds);
            $ids = CatalogProduct::query()->active()->whereIn('external_id', array_column($products, 'id'))->pluck('id', 'external_id')->all();

            $facts = EnrichmentFact::query()->with('vocabulary')->whereIn('product_id', array_values($ids))
                ->where('status', FactStatus::Approved)->whereIn('kind', self::KINDS)->orderBy('id')->get()
                ->groupBy('product_id');

            // row key => [label, kind order, product external id => values]
            $rows = [];
            foreach ($products as $product) {
                foreach ($facts->get($ids[$product['id']] ?? '', collect()) as $fact) {
                    /** @var EnrichmentFact $fact */
                    $key = $fact->kind->value.':'.$fact->key;
                    $value = trim($fact->valueLabel());
                    if ($value === '') {
                        continue;
                    }
                    $rows[$key] ??= ['label' => trim($fact->keyLabel()), 'order' => array_search($fact->kind, self::KINDS, true), 'values' => []];
                    $rows[$key]['values'][$product['id']][$value] = true;
                }
            }

            // The rows most products have first within each kind: the shopper compares like with like.
            uasort($rows, fn (array $a, array $b): int => [$a['order'], -count($a['values']), $a['label']] <=> [$b['order'], -count($b['values']), $b['label']]);

            $table = [];
            foreach ($rows as $key => $row) {
                $values = array_map(fn (array $p): ?string => isset($row['values'][$p['id']]) ? implode(', ', array_keys($row['values'][$p['id']])) : null, $products);
                $table[] = ['key' => $key, 'label' => $row['label'], 'values' => $values, 'differs' => count(array_unique(array_map('strval', $values))) > 1];
            }

            return ['products' => $products, 'rows' => $table];
        } finally {
            app()->setLocale($before);
        }
    }
}
