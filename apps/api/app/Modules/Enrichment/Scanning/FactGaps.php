<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;

/**
 * What most products of a kind are known to have and a few are not: of 41 drills, 37 say what is
 * in the box and 4 say nothing. Those 4 were missed, more often than not, by a pattern their words
 * did not fit ("סוללות כלולות: 2×…") or by a reading that left the detail out.
 *
 * Code only: approved facts grouped by the product's approved type; a detail at least SHARE of a
 * group has is a gap for the rest. Each missing product says whether a value waits for review and
 * which words of the detail's values its own text holds, so the likely misses come first.
 * Inside the shop's tenant.
 */
final class FactGaps
{
    /** A kind with fewer products says nothing about what its products should have. */
    public const MIN_GROUP = 5;

    public const SHARE = 0.6;

    /** Statuses of a value that is there but not yet approved. */
    private const WAITING = [FactStatus::AwaitingReview, FactStatus::AwaitingEscalation, FactStatus::NeedsPerson];

    /**
     * @return list<array{type: string, type_label: string, vocabulary_id: string|null, attribute: string, attribute_label: string, have: int, of: int,
     *     missing: list<array{id: string, external_id: string, title: string, url: string|null, waiting: string|null, hints: list<string>}>}>
     */
    public static function of(): array
    {
        $types = EnrichmentFact::query()->with('vocabulary')->where('kind', FactKind::Type)->where('status', FactStatus::Approved)
            ->whereNotNull('product_id')->orderBy('id')->get()->keyBy('product_id');

        if ($types->isEmpty()) {
            return [];
        }

        $details = EnrichmentFact::query()->with('vocabulary')->whereIn('kind', [FactKind::Choice, FactKind::Spec])
            ->whereIn('product_id', $types->keys())->whereIn('status', [FactStatus::Approved, ...self::WAITING])
            ->orderBy('id')->get();

        // product => attribute => approved values / a value waiting.
        $have = [];
        $waiting = [];
        $labels = [];
        foreach ($details as $fact) {
            if ($fact->status === FactStatus::Approved) {
                $have[$fact->product_id][$fact->key][] = $fact;
                $labels[$fact->key] ??= $fact->keyLabel();
            } else {
                $waiting[$fact->product_id][$fact->key] ??= $fact->valueLabel();
            }
        }

        $groups = $types->groupBy(fn (EnrichmentFact $t): string => (string) $t->vocabulary_id.'|'.$t->value_text);
        $products = CatalogProduct::query()->whereIn('id', $types->keys())->whereNull('removed_at')->get(['id', 'external_id', 'title', 'url', 'payload'])->keyBy('id');
        $gaps = [];

        foreach ($groups as $group) {
            $ids = $group->pluck('product_id')->filter(fn ($id): bool => $products->has($id))->values()->all();

            if (count($ids) < self::MIN_GROUP) {
                continue;
            }

            $attributes = [];
            foreach ($ids as $id) {
                foreach (array_keys($have[$id] ?? []) as $key) {
                    $attributes[$key] = ($attributes[$key] ?? 0) + 1;
                }
            }

            foreach ($attributes as $key => $count) {
                if ($count === count($ids) || $count / count($ids) < self::SHARE) {
                    continue;
                }

                // The words the detail's values are said in, across the group ("סוללות", "מטען").
                $words = [];
                foreach ($ids as $id) {
                    foreach ($have[$id][$key] ?? [] as $fact) {
                        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($fact->valueLabel())) ?: [] as $word) {
                            if (mb_strlen($word) >= 3) {
                                $words[$word] = true;
                            }
                        }
                    }
                }

                $missing = [];
                foreach ($ids as $id) {
                    if (isset($have[$id][$key])) {
                        continue;
                    }

                    $product = $products[$id];
                    $text = mb_strtolower($product->title.' '.strip_tags($product->description()).' '.strip_tags((string) ($product->payload['short_description'] ?? '')));
                    $hints = array_values(array_filter(array_keys($words), fn (string $w): bool => mb_strpos($text, $w) !== false));
                    $missing[] = ['id' => (string) $id, 'external_id' => (string) $product->external_id, 'title' => (string) $product->title, 'url' => $product->url,
                        'waiting' => $waiting[$id][$key] ?? null, 'hints' => array_slice($hints, 0, 6)];
                }

                // What the text speaks of first: the likely misses.
                usort($missing, fn (array $a, array $b): int => [count($b['hints']), $a['title']] <=> [count($a['hints']), $b['title']]);
                $type = $group->first();
                $gaps[] = [
                    'type' => (string) $type->value_text,
                    'type_label' => $type->valueLabel(),
                    'vocabulary_id' => $type->vocabulary_id,
                    'attribute' => (string) $key,
                    'attribute_label' => (string) ($labels[$key] ?? $key),
                    'have' => $count,
                    'of' => count($ids),
                    'missing' => $missing,
                ];
            }
        }

        usort($gaps, fn (array $a, array $b): int => [$b['have'] / $b['of'], $a['type_label']] <=> [$a['have'] / $a['of'], $b['type_label']]);

        return $gaps;
    }
}
