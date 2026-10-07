<?php

namespace App\Modules\Assistant\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use App\Modules\Assistant\Support\Subject;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Answers a shopper's question about one page: a product, or a guide the store published. The
 * Subject says which, carries what the writer may answer from, and picks the prompts for it.
 *
 * 1. The same question asked before about this page: the saved answer, no model. An answer
 *    saved by an older prompt version is asked again.
 * 2. Contact details, too short, over the visitor's or the shop's daily questions: refused in code.
 * 3. A small model decides whether the question is about the page. When it is not, a fixed
 *    refusal, and the writing model never sees the question.
 * 4. The writing model answers from the store's own information first — the product's checked
 *    facts, or the guide's text — and from general knowledge when that does not say.
 * 5. Code refuses an answer with contact details, a price, or a number the store's information
 *    does not have. The small model then checks the answer is about this page, consistent with
 *    the store's information and on topic. Only then the shopper sees it.
 *
 * Every call asks SpendGuard first and records tokens and cost on one run. Every answer is saved.
 */
final class AnswerQuestion
{
    public const AGENT = 'assistant.answerer';

    public const ACTION = 'assistant.answer';

    public const PROMPT_VERSION = 3;

    private const MIN_CHARS = 3;

    private const CHECK_OUTPUT_TOKENS = 400;

    private const MAX_ANSWER_CHARS = 1200;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return array{outcome: string, answer: string, from: string, source?: string} outcome: answered, no_info,
     *                                                                               out_of_scope, invalid, limit or unavailable; from: bank, model or none
     */
    public function handle(string $shopId, string $pageType, string $externalId, string $question, string $visitorHash, string $locale = 'he'): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        $onArticle = $pageType === 'content';

        if (! Features::enabled($onArticle ? 'assistant.on_content' : 'assistant.on_products', $shopId)
            || mb_strlen(Question::normalize($question)) < self::MIN_CHARS) {
            return $this->fixed('invalid', $locale);
        }

        return $this->tenant->run($shopId, function () use ($shopId, $onArticle, $externalId, $question, $visitorHash, $locale): array {
            $maxTextChars = (int) Settings::get('assistant.max_text_chars', $shopId);
            $subject = $onArticle
                ? ($article = CatalogContent::query()->active()->where('external_id', $externalId)->first()) === null ? null : Subject::article($article, $maxTextChars)
                : (($product = CatalogProduct::query()->whereNull('removed_at')->where('external_id', $externalId)->first()) === null ? null : Subject::product($product, $maxTextChars));

            if ($subject === null) {
                return $this->fixed('invalid', $locale);
            }

            $saved = AssistantAnswer::query()->where($subject->column(), $subject->id)->where('question_key', Question::key($question))->first();

            // An answer from an older prompt is asked again: the rules for what shoppers see changed.
            // The team's own answers stay.
            if ($saved !== null && $saved->prompt_version < self::PROMPT_VERSION && $saved->source !== 'team') {
                $saved->delete();
                $saved = null;
            }

            if ($saved !== null) {
                $saved->forceFill(['asked_count' => $saved->asked_count + 1, 'last_asked_at' => now()])->save();

                return $saved->status === AssistantAnswer::SHOWN && $saved->outcome === AssistantAnswer::ANSWERED
                    ? array_filter(['outcome' => $saved->outcome, 'answer' => (string) $saved->answer, 'from' => 'bank', 'source' => $saved->source])
                    : $this->fixed($saved->outcome === AssistantAnswer::OUT_OF_SCOPE ? 'out_of_scope' : 'no_info', $locale, 'bank');
            }

            if (Question::hasContactDetails($question)) {
                return $this->fixed('out_of_scope', $locale);
            }

            if (! $this->withinDailyLimits($shopId, $visitorHash)) {
                return $this->fixed('limit', $locale);
            }

            return $this->ask($shopId, $subject, $question, $locale);
        });
    }

    /** @return array{outcome: string, answer: string, from: string, source?: string} */
    private function ask(string $shopId, Subject $subject, string $question, string $locale): array
    {
        $result = $this->fixed('unavailable', $locale);

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            input: [$subject->kind => $subject->externalId, 'question' => $question],
            work: function (RunContext $run) use ($subject, $question, $locale, &$result): void {
                $answerModel = (string) Settings::get('assistant.answer_model');
                $checkModel = (string) Settings::get('assistant.scope_model');
                $maxOutput = (int) Settings::get('assistant.answer_max_output_tokens');
                $effort = (string) Settings::get('assistant.reasoning_effort');
                $effort = $effort === 'model_default' ? null : $effort;

                $contextJson = (string) json_encode($subject->context, JSON_UNESCAPED_UNICODE);
                $estimatedInput = (int) ceil(mb_strlen($contextJson) / 2) + 800;
                /** @var list<array{0: ModelReply, 1: string}> $replies */
                $replies = [];

                try {
                    $this->spend->assertCanSpend((
                        $estimatedInput * $this->price('answer_input') + $maxOutput * $this->price('answer_output')
                        + ($estimatedInput + 1200) * $this->price('scope_input') + 2 * self::CHECK_OUTPUT_TOKENS * $this->price('scope_output')
                    ) / 1_000_000);

                    $scope = $this->call($run, $checkModel, $subject->prompt('scope'), [$subject->kind => $subject->brief, 'question' => $question], self::CHECK_OUTPUT_TOKENS, $effort);
                    $replies[] = [$scope, 'scope'];

                    if (($scope->data['about_product'] ?? null) !== true) {
                        $this->save($subject, $question, AssistantAnswer::OUT_OF_SCOPE, null, null, $checkModel, $replies, $run);
                        $result = $this->fixed('out_of_scope', $locale, 'model');
                        $run->output(['outcome' => AssistantAnswer::OUT_OF_SCOPE])->summary('assistant::runs.out_of_scope');

                        return;
                    }

                    $reply = $this->call($run, $answerModel, $subject->prompt('answer'), [$subject->kind => $subject->context, 'question' => $question], $maxOutput, $effort, 'answer');
                    $replies[] = [$reply, 'answer'];

                    $answer = mb_substr(trim((string) ($reply->data['answer'] ?? '')), 0, self::MAX_ANSWER_CHARS);
                    $source = in_array($reply->data['source'] ?? null, ['store', 'general'], true) ? $reply->data['source'] : null;
                    $refusal = match (true) {
                        $source === null || $answer === '' => 'no_answer',
                        Question::hasContactDetails($answer) => 'contact_details',
                        self::mentionsPrice($answer) => 'price',
                        self::hasNumberNotIn($answer, $contextJson.' '.$question) => 'number_not_in_product_information',
                        default => null,
                    };

                    if ($refusal === null) {
                        $check = $this->call($run, $checkModel, $subject->prompt('verify'), [$subject->kind => $subject->context, 'question' => $question, 'answer' => $answer], self::CHECK_OUTPUT_TOKENS, $effort);
                        $replies[] = [$check, 'scope'];

                        $refusal = match (false) {
                            ($check->data['about_this_product'] ?? null) === true => 'not_about_this_product',
                            ($check->data['consistent'] ?? null) === true => 'not_consistent',
                            ($check->data['on_topic'] ?? null) === true => 'off_topic',
                            default => null,
                        };
                    }
                } catch (SpendCapReached $e) {
                    $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                $outcome = $refusal === null ? AssistantAnswer::ANSWERED : AssistantAnswer::NO_INFO;
                $this->save($subject, $question, $outcome, $refusal === null ? $answer : null, $refusal === null ? $source : null, $answerModel, $replies, $run);

                $result = $refusal === null
                    ? ['outcome' => $outcome, 'answer' => $answer, 'from' => 'model', 'source' => (string) $source]
                    : $this->fixed('no_info', $locale, 'model');
                $run->output(['outcome' => $outcome, 'source' => $source, 'refused' => $refusal, 'answer' => $answer])->summary('assistant::runs.'.$outcome);
            },
        );

        return $result;
    }

    /** @param array<string, mixed> $input */
    private function call(RunContext $run, string $model, string $prompt, array $input, int $maxOutput, ?string $effort, string $prices = 'scope'): ModelReply
    {
        // The writer and the checker each call the provider the operator chose for them.
        $provider = AgentModel::provider("assistant.{$prices}_provider");
        $reply = $this->models->json($provider, $model, self::prompt($prompt), (string) json_encode($input, JSON_UNESCAPED_UNICODE), $maxOutput, $effort);
        $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output')));

        return $reply;
    }

    private function withinDailyLimits(string $shopId, string $visitorHash): bool
    {
        $visitorKey = "assistant:visitor:{$shopId}:{$visitorHash}";
        $shopKey = "assistant:shop:{$shopId}";

        if (RateLimiter::tooManyAttempts($visitorKey, (int) Settings::get('assistant.questions_per_visitor_per_day', $shopId))
            || RateLimiter::tooManyAttempts($shopKey, (int) Settings::get('assistant.questions_per_shop_per_day', $shopId))) {
            return false;
        }

        RateLimiter::hit($visitorKey, 86400);
        RateLimiter::hit($shopKey, 86400);

        return true;
    }

    /** @param list<array{0: ModelReply, 1: string}> $replies each reply with the prices it is charged at */
    private function save(Subject $subject, string $question, string $outcome, ?string $answer, ?string $source, string $model, array $replies, RunContext $run): void
    {
        $cost = 0.0;
        $input = 0;
        $output = 0;
        foreach ($replies as [$reply, $prices]) {
            $cost += $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output'));
            $input += $reply->inputTokens;
            $output += $reply->outputTokens;
        }

        AssistantAnswer::query()->create([
            'shop_id' => $subject->shopId,
            $subject->column() => $subject->id,
            'question_key' => Question::key($question),
            'question' => $question,
            'answer' => $answer,
            'outcome' => $outcome,
            'source' => $source,
            'prompt_version' => self::PROMPT_VERSION,
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => round($cost, 6),
            'run_id' => $run->runId,
            'last_asked_at' => now(),
        ]);
    }

    private function price(string $name): float
    {
        return (float) Settings::get("assistant.{$name}_usd_per_million");
    }

    /** A price in an answer goes stale; the page shows the real one. */
    private static function mentionsPrice(string $answer): bool
    {
        return preg_match('~₪|ש"ח|ש״ח|\d+\s*שקל~u', $answer) === 1;
    }

    /** A number the store's information and the question do not have is a spec the model made up. */
    private static function hasNumberNotIn(string $answer, string $known): bool
    {
        preg_match_all('~\d+(?:[.,]\d+)?~u', $answer, $numbers);

        foreach ($numbers[0] as $number) {
            if (! str_contains($known, $number) && ! str_contains($known, str_replace(',', '.', $number))) {
                return true;
            }
        }

        return false;
    }

    private static function prompt(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Prompts/'.$name.'.v'.self::PROMPT_VERSION.'.md');
    }

    /** @return array{outcome: string, answer: string, from: string} */
    private function fixed(string $outcome, string $locale, string $from = 'none'): array
    {
        return ['outcome' => $outcome, 'answer' => (string) __('assistant::answers.'.$outcome, [], $locale), 'from' => $from];
    }
}
