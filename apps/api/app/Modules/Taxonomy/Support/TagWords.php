<?php

namespace App\Modules\Taxonomy\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Search\Contracts\ProductTags;
use App\Modules\Taxonomy\Models\TaxonomyAssignment;

/**
 * Each product's kept tags as words the store's search finds it by (taxonomy.in_search): a tag of a
 * searchable group, central enough to the product (taxonomy.search_min_weight_percent: "ישראל" in
 * the background of a book about economics is not), in its Hebrew and English names, its other
 * words, and its broader tag's names. Code only.
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
}
