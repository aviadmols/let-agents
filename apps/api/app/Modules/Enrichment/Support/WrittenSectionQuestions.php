<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Enrichment\Models\EnrichmentSectionQuestions;
use App\Modules\Retrieval\Contracts\SectionQuestions;

/**
 * The questions written for each section text, for the index's second vector of a section. One row
 * per text: a newer prompt's answer replaces an older one when it arrives, and until then the older
 * questions are still the best there are, so whatever is stored is given.
 */
final class WrittenSectionQuestions implements SectionQuestions
{
    /** Hashes asked for in one query, at most. */
    private const PER_QUERY = 500;

    public function questions(string $shopId, array $textHashes): array
    {
        $hashes = array_values(array_unique($textHashes));
        $questions = [];

        foreach (array_chunk($hashes, self::PER_QUERY) as $chunk) {
            $rows = EnrichmentSectionQuestions::query()->where('shop_id', $shopId)->whereIn('text_hash', $chunk)->get(['text_hash', 'questions']);

            foreach ($rows as $row) {
                // Written sorted (SectionQuestionsTask), so the same questions always make the same vector.
                $list = array_values(array_filter((array) $row->questions, fn ($q): bool => is_string($q) && $q !== ''));

                if ($list !== []) {
                    $questions[$row->text_hash] = $list;
                }
            }
        }

        ksort($questions, SORT_STRING);

        return $questions;
    }
}
