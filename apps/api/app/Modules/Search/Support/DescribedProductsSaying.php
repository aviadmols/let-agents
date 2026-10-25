<?php

namespace App\Modules\Search\Support;

use App\Modules\Assistant\Contracts\ProductsDescribed;

/**
 * The products that say a name, from the search index's products only: those whose title says it first ("מיקו דרים"),
 * then those whose description does (DescribedProducts: "שיטאקה" in the formulas' ingredients).
 */
final class DescribedProductsSaying implements ProductsDescribed
{
    public function saying(string $shopId, string $phrase): array
    {
        $index = LoadedIndex::for($shopId);

        if ($index === null || trim($phrase) === '') {
            return [];
        }

        $titled = array_keys(array_filter((array) $index['records'], fn ($r, $id): bool => is_array($r) && str_starts_with((string) $id, 'p:') && HebrewSearch::says((string) ($r['title'] ?? ''), $phrase), ARRAY_FILTER_USE_BOTH));
        $described = array_filter(DescribedProducts::find($shopId, (string) $index['hash'], $phrase), fn (string $id): bool => isset($index['records'][$id]));

        return array_values(array_unique(array_map(fn (string $id): string => substr($id, 2), [...array_map('strval', $titled), ...$described])));
    }
}
