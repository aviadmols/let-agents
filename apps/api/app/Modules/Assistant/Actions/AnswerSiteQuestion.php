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
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Answers a question typed in the store's search box, about the whole site rather than one page.
 * Only ever after the shopper pressed Enter or asked; never while typing.
 *
 * 1. The same question asked before on this site: the saved answer, no model. "Not found" is
 *    asked again after assistant.site_retry_days, in case the store added the page since.
 * 2. Contact details, too short, over the visitor's or the shop's daily questions: refused in code.
 * 3. The store's pages nearest to the question by meaning (one small embedding, kept). None near
 *    enough: "not found", and no writing model is asked.
 * 4. One family writes an answer from those passages only and names the ones it used.
 * 5. Code refuses an answer that cites nothing it was given, has contact details, a price, or a
 *    number the passages and the question do not have.
 * 6. A model of the other family checks every fact is in the cited passages and that it answers
 *    the question. Only then the shopper sees it, with the pages it came from.
 *
 * Every call asks SpendGuard first and records tokens and cost on one run. Every outcome is saved,
 * so the next shopper with the same question gets it with no model.
 */
final class AnswerSiteQuestion
{
    public const AGENT = 'assistant.site_answerer';

    public const ACTION = 'assistant.answer_site';

    public const PROMPT_VERSION = 1;

    private const MIN_CHARS = 3;

    private const CHECK_OUTPUT_TOKENS = 300;

    private const MAX_ANSWER_CHARS = 900;

    private const MAX_SOURCES = 3;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
        private readonly Passages $passages,
    ) {}

    /**
     * @return array{outcome: string, answer: string, from: string, sources?: list<array{title: string, url: string|null, type: string}>}
     *                                                                                                                                    outcome: answered, no_info, out_of_scope, invalid, limit or unavailable; from: bank, model or none
     */
    public function handle(string $shopId, string $question, string $visitorHash, string $locale = 'he'): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));

        if (! Features::enabled('assistant.on_search', $shopId) || mb_strlen(Question::normalize($question)) < self::MIN_CHARS) {
            return $this->fixed('invalid', $locale);
        }

        return $this->tenant->run($shopId, function () use ($shopId, $question, $visitorHash, $locale): array {
            $saved = self::siteAnswers()->where('question_key', Question::key($question))->first();

            if ($saved !== null && $this->stale($saved, $shopId)) {
                $saved->delete();
                $saved = null;
            }

            if ($saved !== null) {
                $saved->forceFill(['asked_count' => $saved->asked_count + 1, 'last_asked_at' => now()])->save();

                return $saved->status === AssistantAnswer::SHOWN && $saved->outcome === AssistantAnswer::ANSWERED
                    ? ['outcome' => $saved->outcome, 'answer' => (string) $saved->answer, 'from' => 'bank', 'sources' => (array) $saved->sources]
                    : $this->fixed($saved->outcome === AssistantAnswer::OUT_OF_SCOPE ? 'out_of_scope' : 'no_info', $locale, 'bank');
            }

            if (Question::hasContactDetails($question)) {
                return $this->fixed('out_of_scope', $locale);
            }

            if (! $this->withinDailyLimits($shopId, $visitorHash)) {
                return $this->fixed('limit', $locale);
            }

            return $this->ask($shopId, $question, $locale);
        });
    }

    /** Answers that belong to the whole site: asked in the search box, not on a page. */
    public static function siteAnswers(): Builder
    {
        return AssistantAnswer::query()->whereNull('product_id')->whereNull('content_id');
    }

    private function stale(AssistantAnswer $saved, string $shopId): bool
    {
        if ($saved->source === 'team') {
            return false;
        }

        return $saved->prompt_version < self::PROMPT_VERSION
            || ($saved->outcome === AssistantAnswer::NO_INFO && $saved->updated_at?->lt(now()->subDays((int) Settings::get('assistant.site_retry_days', $shopId))));
    }

    /** @return array<string, mixed> */
    private function ask(string $shopId, string $question, string $locale): array
    {
        $result = $this->fixed('unavailable', $locale);

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Webhook,
            input: ['question' => $question],
            work: function (RunContext $run) use ($shopId, $question, $locale, &$result): void {
                $writerProvider = AgentModel::provider('assistant.answer_provider');
                $writer = ['provider' => $writerProvider, 'name' => $writerProvider->value, 'model' => (string) Settings::get('assistant.answer_model')];
                $checkerName = strtolower(trim((string) Settings::get('assistant.site_check_provider')));
                $checker = ['provider' => AiProviderName::tryFrom($checkerName), 'name' => $checkerName, 'model' => (string) Settings::get('assistant.site_check_model')];

                if ($checker['provider'] === null || $checker['provider']->family() === $writer['provider']->family()) {
                    $run->fail('assistant::runs.site_same_family', ['family' => $writer['provider']->label()]);

                    return;
                }

                $floor = (float) Settings::get('assistant.site_min_similarity', $shopId);
                $found = array_values(array_filter(
                    $this->passages->near($shopId, $question, ['product', 'content'], (int) Settings::get('assistant.site_passages', $shopId)),
                    fn (array $p): bool => $p['similarity'] >= $floor,
                ));

                if ($found === []) {
                    $this->save($question, AssistantAnswer::NO_INFO, null, [], null, [], $run);
                    $result = $this->fixed('no_info', $locale, 'model');
                    $run->output(['outcome' => AssistantAnswer::NO_INFO, 'passages' => 0])->summary('assistant::runs.site_nothing_near');

                    return;
                }

                $byRef = [];

                foreach ($found as $i => $passage) {
                    $byRef['s'.($i + 1)] = $passage;
                }

                $passages = array_map(fn (string $ref, array $p): array => ['ref' => $ref, 'title' => $p['title'], 'text' => $p['text']], array_keys($byRef), $byRef);
                $maxOutput = (int) Settings::get('assistant.answer_max_output_tokens');
                $effort = (string) Settings::get('assistant.reasoning_effort');
                $effort = $effort === 'model_default' ? null : $effort;
                $replies = [];

                try {
                    $input = (string) json_encode(['question' => $question, 'passages' => $passages], JSON_UNESCAPED_UNICODE);
                    $estimated = (int) ceil(mb_strlen($input) / 2) + 600;
                    $this->spend->assertCanSpend((
                        $estimated * $this->price('answer_input') + $maxOutput * $this->price('answer_output')
                        + ($estimated + 400) * $this->price('site_check_input') + self::CHECK_OUTPUT_TOKENS * $this->price('site_check_output')
                    ) / 1_000_000);

                    $reply = $this->call($run, $writer, 'answer_site', $input, $maxOutput, $effort, 'answer');
                    $replies[] = [$reply, 'answer'];

                    $answer = mb_substr(trim((string) ($reply->data['answer'] ?? '')), 0, self::MAX_ANSWER_CHARS);
                    $cited = array_values(array_unique(array_filter(array_map('strval', (array) ($reply->data['refs'] ?? [])), fn (string $ref): bool => isset($byRef[$ref]))));
                    $citedText = implode("\n", array_map(fn (string $ref): string => $byRef[$ref]['title']."\n".$byRef[$ref]['text'], $cited));

                    $refusal = match (true) {
                        ($reply->data['found'] ?? null) !== true || $answer === '' => 'not_found',
                        $cited === [] => 'cites_nothing',
                        Question::hasContactDetails($answer) => 'contact_details',
                        preg_match('~₪|ש"ח|ש״ח|\d+\s*שקל~u', $answer) === 1 => 'price',
                        self::hasNumberNotIn($answer, $citedText.' '.$question) => 'number_not_in_the_pages',
                        default => null,
                    };

                    if ($refusal === null) {
                        $check = $this->call($run, $checker, 'verify_site', (string) json_encode([
                            'question' => $question,
                            'answer' => $answer,
                            'passages' => array_map(fn (string $ref): array => ['title' => $byRef[$ref]['title'], 'text' => $byRef[$ref]['text']], $cited),
                        ], JSON_UNESCAPED_UNICODE), self::CHECK_OUTPUT_TOKENS, null, 'site_check');
                        $replies[] = [$check, 'site_check'];

                        $refusal = match (false) {
                            ($check->data['supported'] ?? null) === true => 'not_supported',
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

                $sources = $refusal === null ? $this->sources(array_map(fn (string $ref): array => $byRef[$ref], $cited)) : [];
                $outcome = $refusal === null ? AssistantAnswer::ANSWERED : AssistantAnswer::NO_INFO;
                $this->save($question, $outcome, $refusal === null ? $answer : null, $sources, $writer['model'], $replies, $run);

                $result = $refusal === null
                    ? ['outcome' => $outcome, 'answer' => $answer, 'from' => 'model', 'sources' => $sources]
                    : $this->fixed('no_info', $locale, 'model');
                $run->output(['outcome' => $outcome, 'refused' => $refusal, 'cited' => count($cited), 'answer' => $answer, 'checker' => $checker['name'].' · '.$checker['model']])
                    ->summary('assistant::runs.site_'.$outcome);
            },
        );

        return $result;
    }

    /**
     * The pages the answer was written from, as the shopper can open them.
     *
     * @param  list<array{source: string, source_id: string, title: string}>  $cited
     * @return list<array{title: string, url: string|null, type: string}>
     */
    private function sources(array $cited): array
    {
        $out = [];
        $seen = [];

        foreach ($cited as $passage) {
            $key = $passage['source'].'|'.$passage['source_id'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $page = $passage['source'] === 'product'
                ? CatalogProduct::query()->whereKey($passage['source_id'])->first(['title', 'url'])
                : CatalogContent::query()->whereKey($passage['source_id'])->first(['title', 'url']);

            $out[] = ['title' => (string) ($page->title ?? $passage['title']), 'url' => $page?->url, 'type' => $passage['source'] === 'product' ? 'product' : 'content'];

            if (count($out) >= self::MAX_SOURCES) {
                break;
            }
        }

        return $out;
    }

    /** @param array{provider: AiProviderName, name: string, model: string} $role */
    private function call(RunContext $run, array $role, string $prompt, string $input, int $maxOutput, ?string $effort, string $prices): ModelReply
    {
        $reply = $this->models->json($role['provider'], $role['model'], self::prompt($prompt), $input, $maxOutput, $effort);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output')));

        return $reply;
    }

    private function withinDailyLimits(string $shopId, string $visitorHash): bool
    {
        // The same daily allowance as questions asked on a page: one shopper, one shop.
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

    /**
     * @param  list<array{title: string, url: string|null, type: string}>  $sources
     * @param  list<array{0: ModelReply, 1: string}>  $replies
     */
    private function save(string $question, string $outcome, ?string $answer, array $sources, ?string $model, array $replies, RunContext $run): void
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
            'shop_id' => $this->tenant->require(),
            'question_key' => Question::key($question),
            'question' => $question,
            'answer' => $answer,
            'outcome' => $outcome,
            'source' => $answer === null ? null : 'store',
            'sources' => $sources === [] ? null : $sources,
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

    /** A number the cited pages and the question do not have is one the model made up. */
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
        return ['outcome' => $outcome, 'answer' => (string) __('assistant::answers.site.'.$outcome, [], $locale), 'from' => $from];
    }
}
