<?php

namespace App\Modules\Search\Support;

use App\Modules\Assistant\Contracts\ProductsNear;
use App\Modules\Search\Actions\SearchCatalog;

/**
 * The products nearest a question, as the store's search finds them by its words and by meaning (SearchCatalog). Nothing is
 * counted as a search, and a search loosened by dropping a word is no answer to the question as asked.
 */
final class SearchProductsNear implements ProductsNear
{
    public function __construct(private readonly SearchCatalog $search) {}

    public function near(string $shopId, string $question, int $limit): array
    {
        if (trim($question) === '' || $limit < 1) {
            return [];
        }
        $found = $this->search->handle($shopId, $question, ['product'], count: false, perGroup: $limit, meaning: true);
        if ((int) ($found['total'] ?? 0) === 0 || isset($found['loosened'])) {
            return [];
        }

        return array_values(array_unique(array_map('strval', array_column(array_slice($found['groups']['product'] ?? [], 0, $limit), 'external_id'))));
    }
}
