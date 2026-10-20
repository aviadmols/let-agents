<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Contracts\ProductTags;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;
use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyTag;

/**
 * Each product's kept tags as words the store's search finds it by (taxonomy.in_search): a tag of a
 * searchable group, central enough to the product (taxonomy.search_min_weight_percent: "ישראל" in
 * the background of a book about economics is not), in its Hebrew and English names, its other
 * words, and its broader tag's names. Code only.
 *
 * And the tags sure enough to narrow by (taxonomy.in_filters): a filter hides what it does not hold, so
 * only a tag of a group shown as a filter, held by the checker of the other family, central to the
 * product (taxonomy.filter_min_weight_percent), sure (taxonomy.filter_min_confidence_percent), and read
 * from the store's own text or picture: never from what is widely known of a work, which the store
 * does not say. A value on too few products, or on nearly all of them, narrows nothing and is left out.
 */
final class TagWords implements ProductTags
{
    public function wordsByProduct(string $shopId): array
    {
        if (! Features::enabled('taxonomy.in_search', $shopId)) {
            return [];
        }

        $min = (int) Settings::get('taxonomy.search_min_weight_percent', $shopId) / 100;
        $words = [];

        TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->where('status', TaxonomyAssignment::KEPT)
            ->where('weight', '>=', $min)->with(['tag.group', 'tag.parent'])->orderBy('id')->get()
            ->each(function (TaxonomyAssignment $a) use (&$words): void {
                if (! $a->tag->group->searchable) {
                    return;
                }
                foreach ([$a->tag->label_he, $a->tag->label_en, ...(array) $a->tag->words, $a->tag->parent?->label_he, $a->tag->parent?->label_en] as $word) {
                    if (is_string($word) && trim($word) !== '') {
                        $words[$a->subject_id][trim($word)] = true;
                    }
                }
            });

        return array_map(fn (array $w): array => array_keys($w), $words);
    }

    /** A value on nearly every product narrows nothing. */
    private const MAX_SHARE = 0.9;

    public function filters(string $shopId, string $locale): array
    {
        if (! Features::enabled('taxonomy.in_filters', $shopId)) {
            return [];
        }

        $groups = TaxonomyGroup::query()->where('filter', true)->orderBy('position')->orderBy('id')->get()->keyBy('id');
        if ($groups->isEmpty()) {
            return [];
        }

        $total = CatalogProduct::query()->active()->count();
        $held = [];

        TaxonomyAssignment::query()->where('subject_type', TaxonomyAssignment::PRODUCT)->where('status', TaxonomyAssignment::KEPT)
            ->where('checked', 'held')->where('source', '!=', 'knowledge')
            ->where('weight', '>=', (int) Settings::get('taxonomy.filter_min_weight_percent', $shopId) / 100)
            ->where('confidence', '>=', (int) Settings::get('taxonomy.filter_min_confidence_percent', $shopId) / 100)
            ->whereIn('subject_id', CatalogProduct::query()->active()->select('id'))
            ->orderBy('id')->get(['tag_id', 'subject_id'])
            ->each(function (TaxonomyAssignment $a) use (&$held): void {
                $held[$a->tag_id][$a->subject_id] = true;
            });

        $min = (int) Settings::get('taxonomy.filter_min_products', $shopId);
        $most = (int) Settings::get('taxonomy.filter_max_values', $shopId);
        $out = [];
        $tags = TaxonomyTag::query()->whereIn('id', array_keys($held))->where('status', TaxonomyTag::ACTIVE)->whereIn('group_id', $groups->keys()->all())->get()->groupBy('group_id');

        foreach ($groups as $group) {
            $values = $tags->get($group->id, collect())
                ->map(fn (TaxonomyTag $tag): array => ['key' => $tag->key, 'label' => $locale === 'he' ? $tag->label_he : $tag->label_en, 'products' => array_map('strval', array_keys($held[$tag->id]))])
                ->filter(fn (array $v): bool => trim($v['label']) !== '' && count($v['products']) >= $min && ($total === 0 || count($v['products']) / $total < self::MAX_SHARE))
                // the most held first, then by name: the same order every night
                ->sort(fn (array $a, array $b): int => [count($b['products']), $a['label']] <=> [count($a['products']), $b['label']])
                ->take($most)->values()->all();

            // one value is not a choice
            if (count($values) >= 2) {
                $out[] = ['key' => $group->key, 'label' => $group->label($locale), 'values' => $values];
            }
        }

        return $out;
    }
}
