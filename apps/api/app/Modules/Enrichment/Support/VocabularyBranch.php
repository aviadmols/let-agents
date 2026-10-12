<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * The active products in one vocabulary's branch of the catalog: the root category's branch plus
 * any store category a product type names (brushes filed outside the paints root), outside the
 * excluded categories. A root of "*" is the whole catalog, for a store that sells one kind of thing.
 */
final class VocabularyBranch
{
    /** @return Builder<CatalogProduct> */
    public static function products(VocabularyDefinition $vocabulary): Builder
    {
        $categories = CatalogCategory::query()->whereNull('removed_at')->get();
        $root = $categories->firstWhere('external_id', $vocabulary->rootCategoryExternalId());

        if ($root === null && ! $vocabulary->coversWholeCatalog()) {
            return CatalogProduct::query()->whereRaw('1 = 0');
        }

        $excluded = [];
        foreach ($vocabulary->excludedCategoryExternalIds() as $externalId) {
            $category = $categories->firstWhere('external_id', $externalId);
            $excluded = [...$excluded, ...($category?->branchExternalIds($categories) ?? [$externalId])];
        }

        $named = [];
        foreach ($vocabulary->productTypes() as $type) {
            foreach ((array) ($type['categories'] ?? []) as $externalId) {
                $category = $categories->firstWhere('external_id', (string) $externalId);
                $named = [...$named, ...($category?->branchExternalIds($categories) ?? [(string) $externalId])];
            }
        }

        // The whole catalog: every active product, outside the excluded categories.
        if ($root === null) {
            return CatalogProduct::query()
                ->whereNull('removed_at')
                ->when($excluded !== [], fn (Builder $q) => $q->whereDoesntHave('categories', fn (Builder $c) => $c->whereIn('catalog_categories.external_id', $excluded)));
        }

        return CatalogProduct::query()
            ->whereNull('removed_at')
            ->inCategories(array_values(array_unique(array_diff([...$root->branchExternalIds($categories), ...$named], $excluded))))
            ->when($excluded !== [], fn (Builder $q) => $q->whereDoesntHave('categories', fn (Builder $c) => $c->whereIn('catalog_categories.external_id', $excluded)));
    }
}
