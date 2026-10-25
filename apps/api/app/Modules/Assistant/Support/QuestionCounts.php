<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use Carbon\CarbonInterface;

/**
 * How many questions shoppers asked in a period, the one count the home's card and the analytics screen
 * both show: every question in the search window (one row each, by when it was asked), and every question
 * on a product or an article page last asked in the period (those keep one row per question, so a question
 * asked again counts once there). By how each ended. Counts only, inside the shop.
 */
final class QuestionCounts
{
    public const ANSWERED = ['answered'];

    public const UNANSWERED = ['no_info', 'no_match'];

    public const OFF = ['out_of_scope'];

    /** @return array{all: int, answered: int, unanswered: int, off: int, search: int} search: the search window's part */
    public static function between(CarbonInterface $since, CarbonInterface $until): array
    {
        $search = AssistantSearchAsk::query()->where('created_at', '>=', $since)->where('created_at', '<', $until)
            ->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n): int => (int) $n);
        $pages = AssistantAnswer::query()->where(fn ($q) => $q->whereNotNull('product_id')->orWhereNotNull('content_id'))
            ->where('last_asked_at', '>=', $since)->where('last_asked_at', '<', $until)
            ->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n): int => (int) $n);
        $sum = fn (array $outcomes): int => (int) collect($outcomes)->sum(fn (string $o): int => ($search[$o] ?? 0) + ($pages[$o] ?? 0));

        return [
            'all' => (int) ($search->sum() + $pages->sum()),
            'answered' => $sum(self::ANSWERED),
            'unanswered' => $sum(self::UNANSWERED),
            'off' => $sum(self::OFF),
            'search' => (int) $search->sum(),
        ];
    }
}
