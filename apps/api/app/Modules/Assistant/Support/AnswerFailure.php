<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Assistant\Models\AssistantAnswer;

/**
 * Why a shopper's question did not get a good answer, read in code from what the ask kept and what the
 * nightly review said: the first place to look when improving the system. Null when nothing went wrong
 * that code or the review can see. The team's own reading (failure_team) wins on the screen.
 *
 *   missing_info        nothing in the store's pages or products was near the question: a gap in the
 *                       knowledge or the catalogue, fixed by a page, an answer or a product
 *   retrieval           pages or products were found, and the writer found the answer in none of them:
 *                       the wrong things were sent (search, chunking, ranking)
 *   refused_answerable  the review says the answer was there and the assistant refused: the prompt or
 *                       a gate is too strict
 *   invented            the review says the answer says what the sources do not: the writer or its prompt
 *   wrong_products      the review says the products picked do not fit the question
 *   checker_rejected    the checker of the other family stopped the writer's answer
 *   scope               the scope check said the question is not the store's
 *   failed              a model call failed (a provider, a key, an outage)
 *   low_score           the review scored it low without saying why
 */
final class AnswerFailure
{
    public const REASONS = ['missing_info', 'retrieval', 'refused_answerable', 'invented', 'wrong_products', 'checker_rejected', 'scope', 'failed', 'low_score'];

    /** A review score at or under this (of 5) is a failure even without a flag. */
    private const LOW_SCORE = 2;

    /**
     * @param  array<string, mixed>  $trace  what the ask kept (AnswerSiteQuestion's trace)
     * @param  array{score?: int|null, flag?: string|null}|null  $review  the nightly review's item for the ask
     */
    public static function classify(string $outcome, ?string $reason, ?string $verdict, array $trace, ?array $review = null): ?string
    {
        $flag = (string) ($review['flag'] ?? '');

        if (in_array($flag, ['invented', 'refused_answerable', 'wrong_products'], true)) {
            return $flag;
        }

        if (isset($trace['failed'])) {
            return 'failed';
        }

        $found = (int) ($trace['context']['passages_found'] ?? 0) + (int) ($trace['context']['products_sent'] ?? 0);

        $read = match (true) {
            $outcome === AssistantAnswer::OUT_OF_SCOPE && in_array($reason, ['not_about_store', null], true) => 'scope',
            in_array($outcome, [AssistantAnswer::NO_INFO, 'no_match'], true) && $verdict === 'rejected' => 'checker_rejected',
            in_array($outcome, [AssistantAnswer::NO_INFO, 'no_match'], true) && $trace !== [] && $found === 0 => 'missing_info',
            in_array($outcome, [AssistantAnswer::NO_INFO, 'no_match'], true) && $found > 0 => 'retrieval',
            default => null,
        };

        if ($read !== null) {
            return $read;
        }

        return isset($review['score']) && (int) $review['score'] <= self::LOW_SCORE ? 'low_score' : null;
    }
}
