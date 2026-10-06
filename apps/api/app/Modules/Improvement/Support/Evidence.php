<?php

namespace App\Modules\Improvement\Support;

use App\Core\Facades\Settings;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Search\Models\SearchTerm;
use Illuminate\Support\Facades\DB;

/**
 * What shoppers did that the site did not answer, gathered in code, in short lists of fixed
 * length, so a large shop and a small one cost the model the same.
 *
 *   empty      searches that found nothing, at least search.min_searches times
 *   unclicked  searches that found something and were never clicked
 *   questions  questions the assistant could not answer from the site
 *   words      the shop's own category names, so a synonym can point at a word the shop uses
 *
 * An item the review already sent is left out, unless it has at least doubled since: the same
 * evidence is never paid for twice, and a growing problem comes back.
 */
final class Evidence
{
    private const EMPTY = 20;

    private const UNCLICKED = 10;

    private const QUESTIONS = 15;

    private const WORDS = 80;

    private const MEMORY_DAYS = 60;

    /** @return array{empty: list<array{ref: string, query: string, searches: int}>, unclicked: list<array{ref: string, query: string, searches: int, results: int}>, questions: list<array{ref: string, question: string, asked: int, page: string}>, words: list<string>} */
    public static function gather(string $shopId): array
    {
        $since = now()->subDays((int) Settings::get('improvement.evidence_days', $shopId) - 1)->toDateString();
        $min = (int) Settings::get('improvement.min_searches', $shopId);
        $seen = self::seen();

        $grouped = fn () => SearchTerm::query()->where('day', '>=', $since)->select('query')
            ->selectRaw('SUM(searches) as searches, SUM(empty) as empty, SUM(clicks) as clicks, MAX(last_results) as results')
            ->groupBy('query');

        $empty = [];

        foreach ($grouped()->havingRaw('SUM(empty) >= ?', [$min])->orderByDesc(DB::raw('SUM(empty)'))->orderBy('query')->limit(self::EMPTY * 3)->get() as $row) {
            if (self::fresh($seen, 's:'.$row->query, (int) $row->empty) && count($empty) < self::EMPTY) {
                $empty[] = ['ref' => 's'.(count($empty) + 1), 'query' => (string) $row->query, 'searches' => (int) $row->empty];
            }
        }

        $unclicked = [];

        foreach ($grouped()->havingRaw('SUM(searches) >= ? AND SUM(clicks) = 0 AND SUM(empty) < SUM(searches)', [max(5, $min)])->orderByDesc(DB::raw('SUM(searches)'))->orderBy('query')->limit(self::UNCLICKED * 3)->get() as $row) {
            if (self::fresh($seen, 'u:'.$row->query, (int) $row->searches) && count($unclicked) < self::UNCLICKED) {
                $unclicked[] = ['ref' => 'u'.(count($unclicked) + 1), 'query' => (string) $row->query, 'searches' => (int) $row->searches, 'results' => (int) $row->results];
            }
        }

        $questions = [];
        $answers = AssistantAnswer::query()
            ->with(['product:id,title', 'content:id,title'])
            ->where('outcome', AssistantAnswer::NO_INFO)->where('status', AssistantAnswer::SHOWN)
            ->where('last_asked_at', '>=', $since)
            ->orderByDesc('asked_count')->orderBy('id')
            ->limit(self::QUESTIONS * 3)->get();

        foreach ($answers as $answer) {
            if (self::fresh($seen, 'q:'.$answer->question_key, (int) $answer->asked_count) && count($questions) < self::QUESTIONS) {
                $questions[] = [
                    'ref' => 'q'.(count($questions) + 1),
                    'question' => mb_substr((string) $answer->question, 0, 300),
                    'asked' => (int) $answer->asked_count,
                    'page' => mb_substr((string) ($answer->page()?->title ?? ''), 0, 200),
                    'key' => (string) $answer->question_key,
                ];
            }
        }

        $words = CatalogCategory::query()->active()->where('product_count', '>', 0)
            ->orderByDesc('product_count')->orderBy('name')->limit(self::WORDS)->pluck('name')
            ->map(fn ($name): string => (string) $name)->all();

        return ['empty' => $empty, 'unclicked' => $unclicked, 'questions' => $questions, 'words' => $words];
    }

    /** True when there is nothing new for a model to look at. */
    public static function quiet(array $evidence): bool
    {
        return $evidence['empty'] === [] && $evidence['unclicked'] === [] && $evidence['questions'] === [];
    }

    /** The key an item is remembered by, as stored in a review's evidence. */
    public static function keys(array $evidence): array
    {
        $keys = [];

        foreach ($evidence['empty'] ?? [] as $item) {
            $keys['s:'.$item['query']] = $item['searches'];
        }

        foreach ($evidence['unclicked'] ?? [] as $item) {
            $keys['u:'.$item['query']] = $item['searches'];
        }

        foreach ($evidence['questions'] ?? [] as $item) {
            $keys['q:'.($item['key'] ?? $item['question'])] = $item['asked'];
        }

        return $keys;
    }

    /** @return array<string, int> every item the reviews of the last two months sent, with the count it had then */
    private static function seen(): array
    {
        $seen = [];

        foreach (ImprovementReview::query()->where('day', '>=', now()->subDays(self::MEMORY_DAYS)->toDateString())->where('status', ImprovementReview::REVIEWED)->get(['evidence']) as $review) {
            foreach (self::keys((array) $review->evidence) as $key => $count) {
                $seen[$key] = max($seen[$key] ?? 0, (int) $count);
            }
        }

        return $seen;
    }

    /** @param array<string, int> $seen */
    private static function fresh(array $seen, string $key, int $count): bool
    {
        return ! isset($seen[$key]) || $count >= 2 * $seen[$key];
    }
}
