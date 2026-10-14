<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * What a shopper must know before buying and the product does not say. The vocabulary of a branch
 * names those attributes (`importance`: required, recommended), so a gap here is a product of that
 * branch without an approved value for one of them, however few other products have it either:
 * the carat weight of a diamond that no diamond in the store states is a gap on every diamond.
 *
 * FactGaps finds what most of a kind has and a few lack. This finds what the branch asks for.
 * Code only, inside the shop's tenant. A yes-or-no attribute is not counted: code stores only
 * the yes ("battery included"), so a missing yes cannot be told from a no. A vocabulary that
 * needs it known writes it as a choice ("included" or "body only").
 */
final class RequiredGaps
{
    /** Attribute types whose absence of a value means "not known". */
    private const COUNTED_TYPES = ['number', 'enum'];

    /** Statuses of a value that is there but not yet approved. */
    private const WAITING = [FactStatus::AwaitingReview, FactStatus::AwaitingEscalation, FactStatus::NeedsPerson];

    /**
     * @param  list<string>  $levels  the importance levels counted; `required` by default
     * @return list<array{vocabulary_id: string, type: string, type_label: string, attribute: string, attribute_label: string,
     *     importance: string, why: string|null, have: int, of: int,
     *     missing: list<array{id: string, external_id: string, title: string, url: string|null, waiting: string|null}>}>
     */
    public static function of(array $levels = [VocabularyDefinition::REQUIRED], string $locale = 'he'): array
    {
        $vocabularies = EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get();
        $gaps = [];

        foreach ($vocabularies as $vocabulary) {
            $definition = $vocabulary->definition();
            $attributes = array_values(array_filter(
                $definition->attributes(),
                fn (array $a): bool => in_array($definition->importance((string) $a['key']), $levels, true)
                    && in_array($a['type'] ?? 'number', self::COUNTED_TYPES, true),
            ));

            if ($attributes === []) {
                continue;
            }

            $types = EnrichmentFact::query()->where('vocabulary_id', $vocabulary->id)->where('kind', FactKind::Type)
                ->where('status', FactStatus::Approved)->whereNotNull('product_id')->orderBy('id')->pluck('value_text', 'product_id');

            if ($types->isEmpty()) {
                continue;
            }

            $products = CatalogProduct::query()->whereIn('id', $types->keys())->whereNull('removed_at')
                ->get(['id', 'external_id', 'title', 'url'])->keyBy('id');
            $values = EnrichmentFact::query()->whereIn('product_id', $products->keys())->whereIn('kind', [FactKind::Choice, FactKind::Spec])
                ->whereIn('status', [FactStatus::Approved, FactStatus::NotApplicable, ...self::WAITING])->orderBy('id')->get(['product_id', 'key', 'status', 'value_text', 'value_number', 'unit', 'kind']);

            // product => attribute => approved / waiting.
            $have = [];
            $waiting = [];
            foreach ($values as $fact) {
                if ($fact->status === FactStatus::Approved || $fact->status === FactStatus::NotApplicable) {
                    $have[$fact->product_id][$fact->key] = true;
                } else {
                    $waiting[$fact->product_id][$fact->key] ??= $fact->valueLabel();
                }
            }

            foreach ($products->groupBy(fn (CatalogProduct $p): string => (string) $types[$p->id]) as $typeKey => $group) {
                foreach ($attributes as $attribute) {
                    $key = (string) $attribute['key'];

                    if (! $definition->appliesTo($key, (string) $typeKey)) {
                        continue;
                    }

                    $missing = [];
                    foreach ($group as $product) {
                        if (! isset($have[$product->id][$key])) {
                            $missing[] = ['id' => (string) $product->id, 'external_id' => (string) $product->external_id, 'title' => (string) $product->title,
                                'url' => $product->url, 'waiting' => $waiting[$product->id][$key] ?? null];
                        }
                    }

                    if ($missing === []) {
                        continue;
                    }

                    usort($missing, fn (array $a, array $b): int => [$a['waiting'] === null, $a['title']] <=> [$b['waiting'] === null, $b['title']]);
                    $gaps[] = [
                        'vocabulary_id' => (string) $vocabulary->id,
                        'type' => (string) $typeKey,
                        'type_label' => $definition->label('type', (string) $typeKey, $locale),
                        'attribute' => $key,
                        'attribute_label' => $definition->label('attribute', $key, $locale),
                        'importance' => $definition->importance($key),
                        'why' => isset($attribute['why']) ? (string) ($attribute['why'][$locale] ?? $attribute['why']['en'] ?? '') : null,
                        'have' => $group->count() - count($missing),
                        'of' => $group->count(),
                        'missing' => $missing,
                    ];
                }
            }
        }

        // Required before recommended, then the attribute the most products lack.
        usort($gaps, fn (array $a, array $b): int => [$a['importance'] !== VocabularyDefinition::REQUIRED, -count($a['missing']), $a['type_label'], $a['attribute']]
            <=> [$b['importance'] !== VocabularyDefinition::REQUIRED, -count($b['missing']), $b['type_label'], $b['attribute']]);

        return $gaps;
    }
}
