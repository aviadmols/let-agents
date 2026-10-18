<?php

namespace App\Modules\Assistant\Actions;

use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use Illuminate\Support\Carbon;

/**
 * The team's word on a shopper's question, wherever it is given (the questions screen, the analytics
 * list): their own answer, shown to every next shopper and never replaced by a model, or "not relevant",
 * so it is not suggested again. A question from the search window gets a saved answer of its own (no
 * page, the question's key), so asked again it is found. Runs inside the shop's TenantContext.
 */
final class TeamAnswers
{
    public const TEAM = 'team';

    public const MAX = 1200;

    /** The saved answer of a question asked in the search window, made the first time the team acts on it. */
    public function forSearchQuestion(string $question, int $asked = 1, ?Carbon $last = null): AssistantAnswer
    {
        return AssistantAnswer::query()->firstOrCreate(
            ['question_key' => Question::key($question), 'product_id' => null, 'content_id' => null],
            [
                'question' => mb_substr($question, 0, 500), 'outcome' => AssistantAnswer::NO_INFO,
                'source' => 'store', 'status' => AssistantAnswer::SHOWN, 'asked_count' => max(1, $asked),
                'last_asked_at' => $last ?? now(), 'prompt_version' => 1,
            ],
        );
    }

    /** @return 'saved'|'short'|'marked' 'marked': a draft still holding what nothing said is not an answer yet */
    public function answer(AssistantAnswer $answer, string $text): string
    {
        $text = trim($text);

        if (mb_strlen($text) < 2) {
            return 'short';
        }

        if (preg_match('/\[(?:לבדוק|check)\s*:/iu', $text) === 1) {
            return 'marked';
        }

        // Saved through the model: the team's answer also joins what the assistant knows about the store.
        $answer->forceFill([
            'answer' => mb_substr($text, 0, self::MAX),
            'outcome' => AssistantAnswer::ANSWERED,
            'source' => self::TEAM,
            'status' => AssistantAnswer::SHOWN,
            'shared_at' => now(),
        ])->save();

        return 'saved';
    }

    public function setAside(AssistantAnswer $answer): void
    {
        $answer->status = AssistantAnswer::HIDDEN;
        $answer->save();
    }

    /** @return array<string, true> the keys of the search-window questions the team answered or set aside */
    public function handledKeys(): array
    {
        return AssistantAnswer::query()->whereNull('product_id')->whereNull('content_id')
            ->where(fn ($q) => $q->where('source', self::TEAM)->orWhere('status', AssistantAnswer::HIDDEN))
            ->pluck('question_key')->mapWithKeys(fn ($k): array => [(string) $k => true])->all();
    }
}
