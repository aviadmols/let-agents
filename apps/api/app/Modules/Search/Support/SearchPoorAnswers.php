<?php

namespace App\Modules\Search\Support;

use App\Modules\Admin\Contracts\PoorAnswers;
use App\Modules\Search\Models\SearchAnswerRating;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The answers shoppers rated "did not help" in a period (SearchAnswerRating), one row a question however it was typed
 * ("משלוח?", "משלוח"), the most rated first, then the latest: what the store report asks the owner to fix. Code only.
 */
final class SearchPoorAnswers implements PoorAnswers
{
    public function poor(Shop $shop, CarbonInterface $since, CarbonInterface $until, int $limit): array
    {
        $ratings = SearchAnswerRating::query()->with('ask.answer')->where('helpful', false)
            ->where('updated_at', '>=', $since)->where('updated_at', '<', $until)->latest('updated_at')->limit(200)->get();

        $rows = [];
        foreach ($ratings as $rating) {
            $ask = $rating->ask;
            if ($ask === null) {
                continue;
            }
            $question = trim((string) $ask->question);
            $key = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $question)));
            if ($key === '' || $question === '[support]') {
                continue;
            }
            $said = trim((string) ($ask->getAttributes()['answer'] ?? $ask->getRelation('answer')?->answer ?? ''));
            $rows[$key] ??= ['question' => $question, 'answer' => $said, 'downs' => 0];
            $rows[$key]['downs']++;
        }
        $rows = array_values($rows);
        // the most rated first; among equals, the order they came in (the latest first)
        $order = array_keys($rows);
        usort($order, fn (int $a, int $b): int => [$rows[$b]['downs'], $a] <=> [$rows[$a]['downs'], $b]);

        return array_slice(array_map(fn (int $i): array => $rows[$i], $order), 0, $limit);
    }
}
