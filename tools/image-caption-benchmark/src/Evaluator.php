<?php

namespace ImageCaptionBenchmark;

use App\Modules\Search\Support\HebrewSearch;

/**
 * How a flow's picture reading compares with what the catalogue already says. The catalogue is the
 * answer key only where it speaks; where it is silent the reading is "unverified", for a person to
 * judge in the report, not counted right or wrong.
 */
final class Evaluator
{
    /** @return array<string, mixed> */
    public static function product(array $catalogMeta, array $read): array
    {
        $analysis = $read['analysis'];
        $calls = $read['calls'];
        $row = [
            'answered' => $analysis !== null,
            'fallback' => $read['fallback'],
            'cost_usd' => array_sum(array_column($calls, 'cost_usd')),
            'ms' => array_sum(array_column($calls, 'ms')),
            'input_tokens' => array_sum(array_column($calls, 'input_tokens')),
            'output_tokens' => array_sum(array_column($calls, 'output_tokens')),
            'schema_issues' => count(array_filter($read['issues'], fn (string $i): bool => ! str_starts_with($i, 'spec:') && $i !== 'failed')),
            'spec_leaks' => count(array_filter($read['issues'], fn (string $i): bool => str_starts_with($i, 'spec:'))),
            'issues' => $read['issues'],
        ];

        if ($analysis === null) {
            return $row + ['type' => 'failed', 'color' => 'failed', 'material' => 'failed', 'stone' => 'failed', 'conflicts' => [], 'missing' => [], 'unverified' => [], 'new_terms' => 0];
        }

        $custom = $catalogMeta['attributes']['custom'];
        $checks = [
            'type' => [$catalogMeta['identity']['product_type_normalized'] !== null ? [$catalogMeta['identity']['product_type_normalized']] : [], $analysis['product_type_visible'] !== null ? [$analysis['product_type_visible']] : []],
            'color' => [$catalogMeta['attributes']['color'], $analysis['visible_colors']],
            'material' => [$catalogMeta['attributes']['material'], $analysis['visible_materials']],
            'stone' => [$custom['stones'] ?? [], $analysis['visible_stones']],
        ];

        $conflicts = [];
        $missing = [];
        $unverified = [];

        foreach ($checks as $field => [$catalog, $seen]) {
            if ($catalog === []) {
                $row[$field] = $seen === [] ? 'unknown' : 'unverified';
                foreach ($seen as $value) {
                    $unverified[] = $field.':'.$value;
                }

                continue;
            }

            $agrees = fn (string $value): bool => array_filter($catalog, fn (string $c): bool => Vocabulary::sameFamily($c, $value)) !== [];
            $found = array_filter($catalog, fn (string $c): bool => array_filter($seen, fn (string $s): bool => Vocabulary::sameFamily($c, $s)) !== []);

            // A colour the store does not name may still be in the picture (the metal beside a red stone);
            // a material, a stone or a kind of product it contradicts is made up.
            foreach ($seen as $value) {
                if (! $agrees($value)) {
                    if ($field === 'color') {
                        $unverified[] = $field.':'.$value;
                    } else {
                        $conflicts[] = $field.':'.$value;
                    }
                }
            }
            foreach ($catalog as $value) {
                if (! in_array($value, $found, true)) {
                    $missing[] = $field.':'.$value;
                }
            }

            $row[$field] = $found === [] ? ($seen === [] ? 'missed' : 'wrong') : (count($found) === count($catalog) ? 'match' : 'partial');
        }

        // Words the picture adds that the catalogue's own text does not have: what it brings to search.
        $catalogStems = array_flip(array_map([HebrewSearch::class, 'stem'], explode(' ', HebrewSearch::normalize($catalogMeta['text']['search_text'].' '.implode(' ', $catalogMeta['identity']['tags'])))));
        $added = [];
        foreach (explode(' ', HebrewSearch::normalize(implode(' ', [...$analysis['visual_keywords'], ...$analysis['image_search_terms'], ...$analysis['visible_features']]))) as $word) {
            if (mb_strlen($word) > 1 && ! isset($catalogStems[HebrewSearch::stem($word)])) {
                $added[HebrewSearch::stem($word)] = true;
            }
        }

        return $row + ['conflicts' => $conflicts, 'missing' => $missing, 'unverified' => $unverified, 'new_terms' => count($added)];
    }

    /**
     * One flow's totals over every product.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function summary(array $rows, float $sharedCost): array
    {
        $count = max(1, count($rows));
        $rate = function (string $field) use ($rows): ?float {
            $judged = array_filter($rows, fn (array $r): bool => in_array($r[$field], ['match', 'partial', 'wrong', 'missed'], true));

            return $judged === [] ? null : round(100 * count(array_filter($judged, fn (array $r): bool => $r[$field] === 'match' || $r[$field] === 'partial')) / count($judged), 1);
        };
        $own = array_sum(array_column($rows, 'cost_usd'));

        return [
            'products' => count($rows),
            'answered_pct' => round(100 * count(array_filter($rows, fn (array $r): bool => $r['answered'])) / $count, 1),
            'fallback_used' => count(array_filter($rows, fn (array $r): bool => $r['fallback'])),
            'schema_clean_pct' => round(100 * count(array_filter($rows, fn (array $r): bool => $r['answered'] && $r['schema_issues'] === 0)) / $count, 1),
            'spec_leaks' => array_sum(array_column($rows, 'spec_leaks')),
            'type_agrees_pct' => $rate('type'),
            'color_agrees_pct' => $rate('color'),
            'material_agrees_pct' => $rate('material'),
            'stone_agrees_pct' => $rate('stone'),
            'conflicts' => array_sum(array_map(fn (array $r): int => count($r['conflicts']), $rows)),
            'missing' => array_sum(array_map(fn (array $r): int => count($r['missing']), $rows)),
            'unverified' => array_sum(array_map(fn (array $r): int => count($r['unverified']), $rows)),
            'avg_new_terms' => round(array_sum(array_column($rows, 'new_terms')) / $count, 1),
            'avg_ms' => (int) round(array_sum(array_column($rows, 'ms')) / $count),
            'avg_output_tokens' => (int) round(array_sum(array_column($rows, 'output_tokens')) / $count),
            'reading_cost_usd' => round($own, 6),
            'shared_cost_usd' => round($sharedCost, 6),
            'total_cost_usd' => round($own + $sharedCost, 6),
            'per_1000_products_usd' => round(($own + $sharedCost) / $count * 1000, 3),
        ];
    }
}
