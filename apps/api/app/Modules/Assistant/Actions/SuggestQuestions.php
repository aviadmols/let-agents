<?php

namespace App\Modules\Assistant\Actions;

use App\Modules\Assistant\Contracts\SuggestsQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;

/**
 * The questions worth putting in front of someone on this page.
 *
 * Specific before general, in three layers. What the scan found this page itself can answer — the
 * subject it keeps returning to, the number of points in it — because that is the only layer that
 * differs from page to page. Then what shoppers have actually asked here and got a good answer
 * to, most asked first. Then the handful any page of this kind can answer.
 *
 * The scan stored its questions as kinds and subjects rather than sentences, so one reading
 * serves a Hebrew reader and an English one.
 */
final class SuggestQuestions implements SuggestsQuestions
{
    public function for(string $pageId, bool $isArticle, string $locale, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $answered = AssistantAnswer::query()
            ->where($isArticle ? 'content_id' : 'product_id', $pageId)
            ->where('outcome', AssistantAnswer::ANSWERED)
            ->where('status', AssistantAnswer::SHOWN)
            ->shared()
            ->orderByDesc('asked_count')
            ->pluck('question');

        return collect($this->fromTheScan($pageId, $isArticle, $locale))
            ->concat($answered)
            ->concat((array) __($isArticle ? 'assistant::questions.article' : 'assistant::questions.common', [], $locale))
            ->map(fn ($question): string => trim((string) $question))
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * What the scan decided this page is worth being asked, worded for the reader.
     *
     * @return list<string>
     */
    private function fromTheScan(string $pageId, bool $isArticle, string $locale): array
    {
        if (! $isArticle) {
            return [];
        }

        return EnrichmentFact::query()
            ->where('content_id', $pageId)
            ->where('kind', FactKind::Tag)
            ->where('status', FactStatus::Approved)
            ->where('key', 'like', 'ask%')
            ->orderBy('key')
            ->get(['key', 'value_text', 'value_number', 'quote'])
            ->map(function (EnrichmentFact $ask) use ($locale): ?string {
                $key = 'assistant::questions.asked.'.$ask->quote;
                $line = (string) __($key, [
                    'term' => (string) $ask->value_text,
                    'count' => (string) (int) $ask->value_number,
                ], $locale);

                return str_contains($line, 'assistant::') ? null : $line;
            })
            ->filter()
            ->values()
            ->all();
    }
}
