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
        // By its words first, as typed in the search box: a question mark makes the search answer a question (what it is about,
        // by meaning), and "יש תליונים ב-21 קראט?" brought brooches where the same words found the two pendants of 21K gold
        // (Rocks, 18.11). When its words find nothing, as it was asked: "מה מתאים לטיפול בציפורים?" finds the pigeon spikes
        // only as a question (Gueta, 17.11).
        $words = trim((string) preg_replace('/[?？؟!]+/u', ' ', $question));
        foreach (array_unique([$words, trim($question)]) as $asked) {
            if ($asked === '') {
                continue;
            }
            $found = $this->search->handle($shopId, $asked, ['product'], count: false, perGroup: $limit, meaning: true);
            if ((int) ($found['total'] ?? 0) === 0 || isset($found['loosened'])) {
                continue;
            }
            $ids = array_values(array_unique(array_map('strval', array_column(array_slice($found['groups']['product'] ?? [], 0, $limit), 'external_id'))));
            if ($ids !== []) {
                return $ids;
            }
        }

        return [];
    }
}
