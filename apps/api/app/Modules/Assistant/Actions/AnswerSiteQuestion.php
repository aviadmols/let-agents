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
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AnswerContext;
use App\Modules\Assistant\Support\DailyQuestions;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Answers a question typed in the store's search box, about the whole site rather than one page,
 * and picks from the products the search found the ones that truly fit. Only ever after the
 * shopper asked; never while typing.
 *
 * 1. The same question asked before on this site: the saved answer and picks, no model. "Not
 *    found" is asked again after assistant.site_retry_days, in case the store added it since.
 * 2. Contact details, too short, over the visitor's or the shop's daily questions: refused in code.
 * 3. The store's pages nearest to the question by meaning, and the products the shopper was shown.
 *    Neither: "not found", and no writing model is asked.
 * 4. One family writes a short answer from those only, and picks at most three of the products,
 *    each with why. Nothing that fits is a good answer.
 * 5. Code keeps only refs it gave, and refuses contact details, a price, or a number the pages,
 *    the products and the question do not have.
 * 6. A model of the other family checks the answer is in the pages and the picks truly fit.
 *    Picks that do not fit are dropped; an answer the pages do not hold is refused.
 * 7. With no answer and no pick, the shop's WhatsApp is offered (assistant.search_whatsapp).
 *
 * Every question asked is logged with what was shown and what came of it, for the shop's report
 * and the daily review. Every call asks SpendGuard first and records tokens and cost on one run.
 */
final class AnswerSiteQuestion
{
    public const AGENT = 'assistant.site_answerer';

    public const ACTION = 'assistant.answer_site';

    public const PROMPT_VERSION = 3;

    public const MAX_PRODUCTS = 12;

    /** Products a superlative answered in code shows. */
    private const MAX_COMPUTED = 3;

    /** Words a question wraps around the product it names. */
    private const NOT_NAMES = ['איזה', 'איזו', 'אילו', 'מה', 'מהו', 'מהי', 'הכי', 'יש', 'לכם', 'אצלכם', 'אפשר', 'בבקשה', 'שלכם', 'which', 'what', 'the', 'most', 'your'];

    private const MIN_CHARS = 3;

    private const MAX_ANSWER_CHARS = 900;

    private const MAX_SOURCES = 3;

    private const MAX_PICKS = 3;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
        private readonly Passages $passages,
        private readonly AnswerContext $context,
    ) {}

    /**
     * @param  list<string>  $products  external ids of the products the shopper was shown, best first
     * @return array<string, mixed> outcome: answered, no_match, no_info, out_of_scope, invalid, limit or
     *                              unavailable; from: bank, model or none; with sources, picks, ask_id and whatsapp
     */
    public function handle(string $shopId, string $question, string $visitorHash, string $locale = 'he', array $products = []): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));

        if (! Features::enabled('assistant.on_search', $shopId) || mb_strlen(Question::normalize($question)) < self::MIN_CHARS) {
            return $this->fixed('invalid', $locale);
        }

        $products = array_slice(array_values(array_unique(array_map('strval', $products))), 0, self::MAX_PRODUCTS);

        return $this->tenant->run($shopId, function () use ($shopId, $question, $visitorHash, $locale, $products): array {
            $result = $this->answer($shopId, $question, $visitorHash, $locale, $products);

            return $this->logged($shopId, $question, $products, $result);
        });
    }

    /** Answers that belong to the whole site: asked in the search box, not on a page. */
    public static function siteAnswers(): Builder
    {
        return AssistantAnswer::query()->whereNull('product_id')->whereNull('content_id');
    }

    /**
     * @param  list<string>  $products
     * @return array<string, mixed>
     */
    private function answer(string $shopId, string $question, string $visitorHash, string $locale, array $products): array
    {
        // "The cheapest drill" is arithmetic: code answers it from the products found, before any
        // saved answer (prices and stock change) and without a model.
        if (($computed = $this->computed($question, $locale, $products)) !== null) {
            return $computed;
        }

        $saved = self::siteAnswers()->where('question_key', Question::key($question))->first();

        if ($saved !== null && $this->stale($saved, $shopId)) {
            $saved->delete();
            $saved = null;
        }

        if ($saved !== null) {
            $saved->forceFill(['asked_count' => $saved->asked_count + 1, 'last_asked_at' => now()])->save();

            if ($saved->status === AssistantAnswer::SHOWN && $saved->outcome === AssistantAnswer::ANSWERED) {
                return [
                    'outcome' => AssistantAnswer::ANSWERED,
                    'answer' => (string) $saved->answer,
                    'from' => 'bank',
                    'sources' => (array) $saved->sources,
                    'picks' => $this->present((array) $saved->picks),
                    'answer_id' => $saved->id,
                ];
            }

            $outcome = $saved->outcome === AssistantAnswer::OUT_OF_SCOPE ? 'out_of_scope' : ($products === [] ? 'no_info' : 'no_match');

            return $this->fixed($outcome, $locale, 'bank') + ['answer_id' => $saved->id];
        }

        if (Question::hasContactDetails($question)) {
            return $this->fixed('out_of_scope', $locale);
        }

        if (! $this->withinDailyLimits($shopId, $visitorHash)) {
            return $this->fixed('limit', $locale);
        }

        return $this->ask($shopId, $question, $locale, $products);
    }

    private const PAGES_BY_WORDS = 3;

    /** Of the team's answers, how many like the question are read; and how much of an entry. */
    private const TEAM_PAIRS = 4;

    private const KNOWLEDGE_CHARS = 2500;

    /** @return list<string> */
    private function words(string $question): array
    {
        return Question::words($question);
    }

    /**
     * The shop's pages whose title holds a word of the question (a plural as its singular: ביטולים
     * finds ביטול), most words first, before the passages the meaning search found. Inside the
     * shop's tenant.
     *
     * @param  list<array<string, mixed>>  $found
     * @return list<array<string, mixed>>
     */
    private function pagesByWords(string $question, array $found): array
    {
        $words = $this->words($question);

        if ($words === []) {
            return $found;
        }

        $scores = [];

        foreach (array_unique($words) as $word) {
            foreach (CatalogContent::query()->active()->where('type', '!=', CatalogContent::KNOWLEDGE)->where('title', 'like', '%'.$word.'%')->limit(10)->pluck('id') as $id) {
                $scores[(string) $id] = ($scores[(string) $id] ?? 0) + 1;
            }
        }

        arsort($scores);
        $have = array_map(fn (array $p): string => $p['source'].'|'.$p['source_id'], $found);
        $pages = [];

        foreach (CatalogContent::query()->whereIn('id', array_slice(array_keys($scores), 0, self::PAGES_BY_WORDS))->get(['id', 'title', 'body']) as $page) {
            if (in_array('content|'.$page->id, $have, true)) {
                continue;
            }

            $pages[] = ['source' => 'content', 'source_id' => (string) $page->id, 'external_id' => null, 'title' => (string) $page->title, 'text' => mb_substr(trim((string) $page->body), 0, 1500), 'similarity' => 1.0, 'kind' => 'title'];
        }

        return [...$pages, ...$found];
    }

    /**
     * The store's knowledge entries as the first passages: what its product pages repeat and what
     * its team wrote, whole; of the team's answers, only those that share a word with the question.
     */
    private function knowledge(string $question, string $shopId, array $found): array
    {
        if (! Features::enabled('assistant.knowledge', $shopId)) {
            return $found;
        }

        $words = $this->words($question);
        $first = [];

        foreach (CatalogContent::query()->active()->where('type', CatalogContent::KNOWLEDGE)->orderBy('external_id')->get(['id', 'external_id', 'title', 'body']) as $entry) {
            $text = trim((string) $entry->body);

            if ($entry->external_id === BuildShopKnowledge::TEAM) {
                $pairs = array_filter(preg_split('/\n{2,}/u', $text) ?: [], function (string $pair) use ($words): bool {
                    foreach ($words as $word) {
                        if (mb_stripos($pair, $word) !== false) {
                            return true;
                        }
                    }

                    return false;
                });
                $text = implode("\n\n", array_slice($pairs, 0, self::TEAM_PAIRS));
            }

            if ($text !== '') {
                $first[] = ['source' => 'content', 'source_id' => (string) $entry->id, 'external_id' => null, 'title' => (string) $entry->title, 'text' => mb_substr($text, 0, self::KNOWLEDGE_CHARS), 'similarity' => 1.0, 'kind' => 'knowledge'];
            }
        }

        $ids = array_map(fn (array $p): string => $p['source_id'], $first);

        return [...$first, ...array_values(array_filter($found, fn (array $p): bool => $p['source'] !== 'content' || ! in_array($p['source_id'], $ids, true)))];
    }

    private function stale(AssistantAnswer $saved, string $shopId): bool
    {
        if ($saved->source === 'team') {
            return false;
        }

        if ($saved->prompt_version < self::PROMPT_VERSION) {
            return true;
        }

        if ($saved->outcome !== AssistantAnswer::NO_INFO) {
            return false;
        }

        // "Not on the site" holds only as long as the site does: a page added or changed since
        // (a cancellation policy synced later) is read before saying so again.
        $changed = CatalogContent::query()->active()->max('updated_at');

        return $saved->updated_at?->lt(now()->subDays((int) Settings::get('assistant.site_retry_days', $shopId)))
            || ($changed !== null && $saved->updated_at !== null && $saved->updated_at->lt(Carbon::parse($changed)));
    }

    /**
     * One question answered as the search box would, but without the saved answers, the daily
     * limits, the shopper's log, or saving the answer: for comparing context modes on fixed
     * questions. The run is recorded (its cost is real) and marked as an evaluation.
     *
     * @param  list<string>  $products  external ids shown, best first
     * @return array{result: array<string, mixed>, trace: array<string, mixed>}
     */
    public function evaluate(string $shopId, string $question, array $products, string $mode, string $locale = 'he'): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        $products = array_slice(array_values(array_unique(array_map('strval', $products))), 0, self::MAX_PRODUCTS);

        return $this->tenant->run($shopId, function () use ($shopId, $question, $products, $mode, $locale): array {
            if (($computed = $this->computed($question, $locale, $products)) !== null) {
                return ['result' => $computed, 'trace' => ['from' => 'code']];
            }

            $trace = [];
            $result = $this->ask($shopId, $question, $locale, $products, $mode, false, $trace);

            return ['result' => $result, 'trace' => $trace];
        });
    }

    /**
     * What the writing model would be given, in each mode, with why each product and passage was
     * kept: no model is called. For the dry evaluation.
     *
     * @param  list<string>  $products
     * @return array<string, array<string, mixed>> by mode
     */
    public function preview(string $shopId, string $question, array $products): array
    {
        $question = Question::clean($question, (int) Settings::get('assistant.max_question_chars', $shopId));
        $products = array_slice(array_values(array_unique(array_map('strval', $products))), 0, self::MAX_PRODUCTS);

        return $this->tenant->run($shopId, function () use ($shopId, $question, $products): array {
            $out = [];

            foreach ([AnswerContext::FULL, AnswerContext::DYNAMIC] as $mode) {
                $prepared = $this->prepare($shopId, $question, $products, $mode);
                $out[$mode] = [
                    'type' => $prepared['type'],
                    'empty' => $prepared['empty'],
                    'products' => array_map(fn (array $p): array => ['external_id' => $p['external_id'], 'title' => $p['title']], array_values($prepared['productsByRef'])),
                    'passages' => array_map(fn (array $p): array => ['title' => $p['title'], 'kind' => $p['kind'] ?? 'meaning', 'chars' => mb_strlen((string) $p['text'])], array_values($prepared['byRef'])),
                    'input_chars' => mb_strlen($prepared['input']),
                    'estimated_input_tokens' => (int) ceil(mb_strlen($prepared['input']) / 2) + 600,
                    'debug' => $prepared['debug'],
                ];
            }

            return $out;
        });
    }

    /**
     * What the writer is given for this question: the passages and products found, chosen in
     * dynamic mode, numbered, read by code, and written as the input. The one place the context
     * is built, for answering, evaluating and previewing alike.
     *
     * @param  list<string>  $shown
     * @return array{type: string, empty: bool, found: int, shown: int, byRef: array<string, array<string, mixed>>, productsByRef: array<string, array<string, mixed>>, analysis: array<string, mixed>, forModel: list<array<string, mixed>>, input: string, debug: array<string, mixed>|null}
     */
    private function prepare(string $shopId, string $question, array $shown, string $mode): array
    {
        $floor = (float) Settings::get('assistant.site_min_similarity', $shopId);
        $found = array_values(array_filter(
            $this->passages->near($shopId, $question, ['product', 'content'], (int) Settings::get('assistant.site_passages', $shopId)),
            fn (array $p): bool => $p['similarity'] >= $floor,
        ));
        // Code before model: the pages whose title holds the question's words ("ביטול עסקה" for
        // "מה מדיניות הביטולים") are read first, whatever the meaning search found.
        $found = $this->pagesByWords($question, $found);
        // Before all: what the store says about itself on every product page, in its team's words,
        // and the team's answers to questions like this one.
        $found = $this->knowledge($question, $shopId, $found);
        $candidates = $this->candidates($shown);
        $type = AnswerContext::type($question);
        $counts = ['found' => count($found), 'shown' => count($candidates)];
        $debug = null;

        // Nothing at all is the same answer in every mode: decided before any choice is made.
        if ($found !== [] || $candidates !== []) {
            if ($mode === AnswerContext::DYNAMIC) {
                $selection = $this->context->select($shopId, $question, $type, $candidates, $found);
                [$candidates, $found, $debug] = [$selection['products'], $selection['passages'], $selection['debug']];
            }
        }

        $byRef = [];
        foreach ($found as $i => $passage) {
            $byRef['s'.($i + 1)] = $passage;
        }
        $productsByRef = [];
        foreach ($candidates as $i => $product) {
            $productsByRef['p'.($i + 1)] = $product;
        }

        // Code reads the results before the model: price order among what is in stock, what
        // is on sale, the brands, and whether the question asks for the cheapest or the dearest.
        // The model gets ranks, never prices: the page shows the current price.
        $analysis = self::analysis($question, $productsByRef);
        $forModel = $this->forModel($productsByRef, $analysis);

        if ($mode === AnswerContext::DYNAMIC) {
            $forModel = array_map(fn (array $entry): array => AnswerContext::fields($type, $entry, $productsByRef[$entry['ref']], $question), $forModel);
        }

        $input = (string) json_encode([
            'question' => $question,
            'products' => $forModel,
            'analysis' => $analysis,
            'passages' => array_map(fn (string $ref, array $p): array => ['ref' => $ref, 'title' => $p['title'], 'text' => $p['text']], array_keys($byRef), $byRef),
        ], JSON_UNESCAPED_UNICODE);

        return ['type' => $type, 'empty' => $counts['found'] === 0 && $counts['shown'] === 0] + $counts
            + compact('byRef', 'productsByRef', 'analysis', 'forModel', 'input', 'debug');
    }

    /**
     * @param  list<string>  $shown
     * @param  string|null  $mode  the shop's assistant.context_mode unless given
     * @param  bool  $remember  false for an evaluation: nothing is saved for the next shopper
     * @param  array<string, mixed>|null  $trace  filled with what was given and what came of it
     * @return array<string, mixed>
     */
    private function ask(string $shopId, string $question, string $locale, array $shown, ?string $mode = null, bool $remember = true, ?array &$trace = null): array
    {
        $result = $this->fixed('unavailable', $locale);
        $mode ??= (string) Settings::get('assistant.context_mode', $shopId);

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: $remember ? RunTrigger::Webhook : RunTrigger::Manual,
            input: ['question' => $question, 'products' => count($shown)] + ($remember ? [] : ['evaluation' => $mode]),
            work: function (RunContext $run) use ($shopId, $question, $locale, $shown, $mode, $remember, &$result, &$trace): void {
                $writer = AgentModel::from('assistant.answer_provider', 'assistant.answer_model');
                $writer = ['provider' => $writer->provider, 'name' => $writer->provider->value, 'model' => $writer->model];
                $checkerName = strtolower(trim((string) Settings::get('assistant.site_check_provider')));
                $checker = ['provider' => AiProviderName::tryFrom($checkerName), 'name' => $checkerName, 'model' => (string) Settings::get('assistant.site_check_model')];

                if ($checker['provider'] === null || $checker['provider']->family() === $writer['provider']->family()) {
                    $run->fail('assistant::runs.site_same_family', ['family' => $writer['provider']->label()]);

                    return;
                }

                $prepared = $this->prepare($shopId, $question, $shown, $mode);
                ['byRef' => $byRef, 'productsByRef' => $productsByRef, 'analysis' => $analysis, 'forModel' => $forModel, 'input' => $input] = $prepared;
                $context = ['mode' => $mode, 'type' => $prepared['type'], 'constraints' => AnswerContext::constraints($question), 'products_shown' => $prepared['shown'], 'products_sent' => count($productsByRef),
                    'passages_found' => $prepared['found'], 'passages_sent' => count($byRef), 'chars' => mb_strlen($input)];
                $trace = ['context' => $context, 'debug' => $prepared['debug']];
                $noAnswer = $shown === [] ? AssistantAnswer::NO_INFO : 'no_match';

                if ($prepared['empty']) {
                    if ($remember) {
                        $this->save($question, AssistantAnswer::NO_INFO, null, [], [], null, [], $run);
                    }
                    $result = $this->fixed($noAnswer, $locale, 'model');
                    $run->output(['outcome' => AssistantAnswer::NO_INFO, 'passages' => 0, 'products' => 0, 'context' => $context])->summary('assistant::runs.site_nothing_near');

                    return;
                }

                $maxOutput = (int) Settings::get('assistant.answer_max_output_tokens');
                $checkOutput = (int) Settings::get('assistant.site_check_max_output_tokens');
                $effort = (string) Settings::get('assistant.reasoning_effort');
                $effort = $effort === 'model_default' ? null : $effort;
                $replies = [];
                // What the checker did with this answer, kept on the run: how often it changes an answer,
                // for which kind of question, at what cost and how fast. Read by assistant:checker-report.
                $checked = ['ran' => false];

                $wrote = [];

                try {
                    $estimated = (int) ceil(mb_strlen($input) / 2) + 600;
                    $this->spend->assertCanSpend((
                        $estimated * $this->price('answer_input') + $maxOutput * $this->price('answer_output')
                        + ($estimated + 400) * $this->price('site_check_input') + $checkOutput * $this->price('site_check_output')
                    ) / 1_000_000);

                    $started = hrtime(true);
                    $reply = $this->call($run, $writer, 'answer_site', $input, $maxOutput, $effort, 'answer');
                    $replies[] = [$reply, 'answer'];
                    // Token counts as "in" and "out": a key holding "token" is redacted on a run.
                    $wrote = ['model' => $writer['model'], 'ms' => (int) round((hrtime(true) - $started) / 1e6), 'in' => $reply->inputTokens, 'out' => $reply->outputTokens,
                        'cost_usd' => round($reply->costUsd($this->price('answer_input'), $this->price('answer_output')), 6)];

                    $answer = mb_substr(trim((string) ($reply->data['answer'] ?? '')), 0, self::MAX_ANSWER_CHARS);
                    $cited = array_values(array_unique(array_filter(array_map('strval', (array) ($reply->data['refs'] ?? [])), fn (string $ref): bool => isset($byRef[$ref]))));
                    $picks = $this->picks((array) ($reply->data['picks'] ?? []), $productsByRef);
                    $known = implode("\n", array_map(fn (string $ref): string => $byRef[$ref]['title']."\n".$byRef[$ref]['text'], $cited))
                        ."\n".implode("\n", array_map(fn (array $p): string => $p['title'].' '.$p['about'], $productsByRef))."\n".$question;

                    // A size or a measure the model was given in dynamic mode is in what it was given.
                    if ($mode === AnswerContext::DYNAMIC) {
                        $known .= "\n".json_encode($forModel, JSON_UNESCAPED_UNICODE);
                    }

                    // Code's checks on the answer; the picks stand or fall on their own.
                    $answerRefusal = match (true) {
                        $answer === '' => 'no_answer',
                        $cited === [] && $picks === [] => 'cites_nothing',
                        Question::hasContactDetails($answer) => 'contact_details',
                        preg_match('~₪|ש"ח|ש״ח|\d+\s*שקל~u', $answer) === 1 => 'price',
                        self::hasNumberNotIn($answer, $known) => 'number_not_in_the_pages',
                        default => null,
                    };

                    if ($answerRefusal !== null) {
                        $answer = '';
                    }

                    $checked['type'] = self::questionType($analysis['asks'] ?? null, $cited, $picks);

                    if (($reply->data['found'] ?? null) !== true || ($answer === '' && $picks === [])) {
                        $refusal = $answerRefusal ?? 'not_found';
                        $checked['skipped'] = $refusal;
                    } else {
                        $before = ['answer' => $answer !== '', 'picks' => $picks !== []];
                        $started = hrtime(true);
                        $check = $this->call($run, $checker, 'verify_site', (string) json_encode([
                            'question' => $question,
                            'answer' => $answer,
                            'passages' => array_map(fn (string $ref): array => ['title' => $byRef[$ref]['title'], 'text' => $byRef[$ref]['text']], $cited),
                            'picks' => array_map(fn (array $pick): array => ['title' => $pick['title'], 'about' => $productsByRef[$pick['ref']]['about'], 'why' => $pick['why']] + array_intersect_key($this->forModel([$pick['ref'] => $productsByRef[$pick['ref']]], $analysis)[0], array_flip(['brand', 'in_stock', 'on_sale', 'price_rank'])), $picks),
                            'analysis' => $analysis,
                        ], JSON_UNESCAPED_UNICODE), $checkOutput, null, 'site_check');
                        $replies[] = [$check, 'site_check'];
                        $checked += [
                            'model' => $checker['model'],
                            'ms' => (int) round((hrtime(true) - $started) / 1e6),
                            // Token counts as "in" and "out": a key holding "token" is redacted on a run.
                            'in' => $check->inputTokens,
                            'out' => $check->outputTokens,
                            'cost_usd' => round($check->costUsd($this->price('site_check_input'), $this->price('site_check_output')), 6),
                        ];
                        $checked['ran'] = true;

                        $verdict = [];
                        if (($check->data['picks_fit'] ?? null) !== true) {
                            $picks = [];
                            $verdict[] = 'picks';
                        }
                        if (($check->data['supported'] ?? null) !== true || ($check->data['on_topic'] ?? null) !== true) {
                            $answer = '';
                            $verdict[] = ($check->data['on_topic'] ?? null) !== true ? 'off_topic' : 'unsupported';
                        }

                        $refusal = $answer === '' && $picks === [] ? 'check_'.implode('_', $verdict ?: ['empty']) : null;
                        $checked['verdict'] = match (true) {
                            $refusal !== null => 'rejected',
                            $before['answer'] && $answer === '' => 'answer_removed',
                            $before['picks'] && $picks === [] => 'picks_removed',
                            default => 'kept',
                        };
                    }
                } catch (SpendCapReached $e) {
                    $run->fail('assistant::runs.spend_cap', [], $e->getMessage());

                    return;
                } catch (ModelCallFailed $e) {
                    $trace += ['failed' => $e->reason, 'writer' => $wrote, 'check' => $checked];
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                $sources = $refusal === null ? $this->sources(array_map(fn (string $ref): array => $byRef[$ref], $cited)) : [];
                $stored = array_map(fn (array $pick): array => ['external_id' => $pick['external_id'], 'title' => $pick['title'], 'why' => $pick['why']], $picks);
                $outcome = $refusal === null ? AssistantAnswer::ANSWERED : AssistantAnswer::NO_INFO;
                $saved = $remember ? $this->save($question, $outcome, $refusal === null ? $answer : null, $sources, $stored, $writer['model'], $replies, $run) : null;

                $result = $refusal === null
                    ? ['outcome' => $outcome, 'answer' => $answer, 'from' => 'model', 'sources' => $sources, 'picks' => $this->present($stored), 'answer_id' => $saved?->id]
                    : $this->fixed($noAnswer, $locale, 'model') + ['answer_id' => $saved?->id, 'reason' => mb_substr((string) $refusal, 0, 40)];
                $trace += ['writer' => $wrote, 'check' => $checked, 'refused' => $refusal, 'picks' => array_column($stored, 'external_id')];
                $run->output(['outcome' => $outcome, 'refused' => $refusal, 'cited' => count($cited), 'picks' => count($picks), 'answer' => $answer, 'checker' => $checker['name'].' · '.$checker['model'],
                    'check' => $checked, 'context' => $trace['context'], 'writer' => $wrote])
                    ->summary('assistant::runs.site_'.$outcome);
            },
        );

        return $result;
    }

    /**
     * What code can say about the products found, so the model need not guess: which are in stock
     * from the cheapest up, which are on sale, which brands, and what the question asks for. A
     * superlative is computed here, never by a model.
     *
     * @param  array<string, array{price: float|null, in_stock: bool, on_sale: bool, brand: string|null}>  $productsByRef
     * @return array{asks: string|null, in_stock_cheapest_first: list<string>, out_of_stock: list<string>, on_sale: list<string>, brands: list<string>}
     */
    public static function analysis(string $question, array $productsByRef): array
    {
        $priced = array_filter($productsByRef, fn (array $p): bool => $p['in_stock'] && $p['price'] !== null && $p['price'] > 0);
        uasort($priced, fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        $text = mb_strtolower($question);

        return [
            'asks' => match (true) {
                preg_match('/זול|במחיר\s+נמוך|הכי\s+משתלם|cheap|budget/u', $text) === 1 => 'cheapest',
                preg_match('/יקר|expensive/u', $text) === 1 => 'most_expensive',
                preg_match('/מבצע|הנחה|sale|discount/u', $text) === 1 => 'on_sale',
                default => null,
            },
            'in_stock_cheapest_first' => array_keys($priced),
            'out_of_stock' => array_keys(array_filter($productsByRef, fn (array $p): bool => ! $p['in_stock'])),
            'on_sale' => array_keys(array_filter($productsByRef, fn (array $p): bool => $p['on_sale'] && $p['in_stock'])),
            'brands' => array_values(array_unique(array_filter(array_map(fn (array $p): ?string => $p['brand'] ?: null, $productsByRef)))),
        ];
    }

    /**
     * A superlative answered in code: the products found that are what the question names (a
     * drill, not "a bit for a drill"), in stock, ordered by price or on sale. Null when the
     * question asks for no superlative or none of the products is what it names: then a model
     * reads it.
     *
     * @param  list<string>  $shown
     * @return array<string, mixed>|null
     */
    private function computed(string $question, string $locale, array $shown): ?array
    {
        $productsByRef = [];
        foreach ($this->candidates($shown) as $i => $product) {
            $productsByRef['p'.($i + 1)] = $product;
        }

        $asks = self::analysis($question, $productsByRef)['asks'];

        if ($asks === null || $productsByRef === []) {
            return null;
        }

        $named = self::named($question);
        $fit = array_filter($productsByRef, fn (array $p): bool => $p['in_stock'] && $p['price'] !== null && $p['price'] > 0
            && ($named === [] || self::titleNames($p['title'], $named)) && ($asks !== 'on_sale' || $p['on_sale']));

        if ($fit === []) {
            return null;
        }

        uasort($fit, fn (array $a, array $b): int => $asks === 'most_expensive' ? $b['price'] <=> $a['price'] : $a['price'] <=> $b['price']);
        $picks = [];

        foreach (array_slice(array_values($fit), 0, self::MAX_COMPUTED) as $at => $product) {
            $picks[] = ['external_id' => $product['external_id'], 'title' => $product['title'], 'why' => (string) __('assistant::answers.site.computed.why_'.$asks.($at === 0 ? '' : '_next'), [], $locale)];
        }

        return [
            'outcome' => AssistantAnswer::ANSWERED,
            'answer' => (string) __('assistant::answers.site.computed.'.$asks, ['title' => $picks[0]['title']], $locale),
            'from' => 'code',
            'sources' => [],
            'picks' => $this->present($picks),
        ];
    }

    /**
     * The words a question names its product with: no question, filler or superlative words,
     * each by its stem. "איזו מברגה הכי זולה" names "מברג".
     *
     * @return list<string>
     */
    private static function named(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Question::normalize($question)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && ! in_array($word, self::NOT_NAMES, true) && preg_match('/^(זול|יקר|במבצע|מבצע|הנחה|cheap|expensive)/u', $word) !== 1) {
                $out[] = self::stem($word);
            }
        }

        return array_values(array_unique($out));
    }

    /** Whether a title has a word that starts with one of the named stems ("למברגה" does not). */
    private static function titleNames(string $title, array $named): bool
    {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach ($named as $stem) {
                if (str_starts_with($word, $stem)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** מברגה, מברגות → מברג. */
    private static function stem(string $word): string
    {
        return mb_strlen($word) > 3 ? (string) preg_replace('/(ות|ים|ה|ת)$/u', '', $word) : $word;
    }

    /**
     * The products as the model sees them: what they are, and code's ranks instead of prices.
     *
     * @param  array<string, array<string, mixed>>  $productsByRef
     * @param  array{in_stock_cheapest_first: list<string>}  $analysis
     * @return list<array<string, mixed>>
     */
    private function forModel(array $productsByRef, array $analysis): array
    {
        $rank = array_flip($analysis['in_stock_cheapest_first']);
        $out = [];

        foreach ($productsByRef as $ref => $p) {
            $out[] = array_filter([
                'ref' => $ref,
                'title' => $p['title'],
                'about' => $p['about'],
                'category' => $p['category'],
                'brand' => $p['brand'] ?? null,
                'in_stock' => $p['in_stock'],
                'on_sale' => $p['on_sale'] ?: null,
                'price_rank' => isset($rank[$ref]) ? $rank[$ref] + 1 : null,
            ], fn ($v): bool => $v !== null && $v !== '');
        }

        return $out;
    }

    /**
     * The products the shopper was shown, in the order shown, with the little the writer needs.
     *
     * @param  list<string>  $shown
     * @return list<array{external_id: string, title: string, about: string, category: string}>
     */
    private function candidates(array $shown): array
    {
        if ($shown === []) {
            return [];
        }

        $products = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', $shown)->get()->keyBy('external_id');
        $out = [];

        foreach ($shown as $id) {
            $product = $products->get($id);

            if ($product === null) {
                continue;
            }

            $out[] = [
                'external_id' => (string) $product->external_id,
                'title' => (string) $product->title,
                'about' => mb_substr(trim(strip_tags((string) $product->shortDescription())), 0, 300),
                'category' => implode(' > ', $product->categoryPaths()[0] ?? []),
                'brand' => $product->brand,
                'in_stock' => (bool) $product->in_stock,
                'on_sale' => (bool) $product->on_sale,
                'price' => $product->price !== null ? (float) $product->price : null,
            ] + self::details($product);
        }

        return $out;
    }

    /**
     * What a product says besides its description, for the kinds of question that ask about it in
     * dynamic mode: its options, its sizes, its measures, and which options are in stock. Full mode
     * never sends these.
     *
     * @return array{sku: string, attributes: list<string>, sizes: list<string>, measures: list<string>, options_in_stock: list<string>}
     */
    private static function details(CatalogProduct $product): array
    {
        $attributes = array_map(fn (array $a): string => $a['name'].': '.implode(', ', $a['values']), $product->storeAttributes());
        $sizes = [];
        foreach ($product->storeAttributes() as $attribute) {
            if (preg_match('/^(size|מיד|גודל)/iu', (string) $attribute['name']) === 1) {
                $sizes = [...$sizes, ...$attribute['values']];
            }
        }

        $dimensions = (array) ($product->payload['dimensions'] ?? []);
        $measures = [];
        foreach (['weight' => 'weight_unit', 'length' => 'dimension_unit', 'width' => 'dimension_unit', 'height' => 'dimension_unit'] as $field => $unit) {
            if (is_numeric($dimensions[$field] ?? null) && (float) $dimensions[$field] > 0) {
                $measures[] = $field.': '.$dimensions[$field].' '.($dimensions[$unit] ?? '');
            }
        }

        $inStock = [];
        foreach ((array) ($product->payload['variations'] ?? []) as $variation) {
            if (($variation['stock']['in_stock'] ?? false) === true) {
                $inStock = [...$inStock, ...array_map(fn ($a): string => (string) ($a['value'] ?? ''), (array) ($variation['attributes'] ?? []))];
            }
        }

        return [
            'sku' => (string) $product->sku,
            'attributes' => $attributes,
            'sizes' => array_values(array_unique(array_map('strval', $sizes))),
            'measures' => array_map('trim', $measures),
            'options_in_stock' => array_values(array_unique(array_filter($inStock))),
        ];
    }

    /**
     * Code's gate on the picks: only products it offered, each once, at most three, each with why.
     *
     * @param  array<int, mixed>  $raw
     * @param  array<string, array{external_id: string, title: string, about: string, category: string}>  $productsByRef
     * @return list<array{ref: string, external_id: string, title: string, why: string}>
     */
    private function picks(array $raw, array $productsByRef): array
    {
        $out = [];
        $seen = [];

        foreach ($raw as $pick) {
            $ref = is_array($pick) ? (string) ($pick['ref'] ?? '') : '';
            $why = is_array($pick) ? mb_substr(trim((string) ($pick['why'] ?? '')), 0, 160) : '';

            if (! isset($productsByRef[$ref]) || isset($seen[$ref]) || $why === '' || Question::hasContactDetails($why)) {
                continue;
            }

            $seen[$ref] = true;
            $out[] = ['ref' => $ref, 'external_id' => $productsByRef[$ref]['external_id'], 'title' => $productsByRef[$ref]['title'], 'why' => $why];

            if (count($out) >= self::MAX_PICKS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Picks as the shopper sees them: still on sale, with their link and picture today.
     *
     * @param  list<array{external_id: string, title: string, why: string}>  $picks
     * @return list<array{external_id: string, title: string, url: string|null, image: string|null, why: string}>
     */
    private function present(array $picks): array
    {
        if ($picks === []) {
            return [];
        }

        $products = CatalogProduct::query()->whereNull('removed_at')->whereIn('external_id', array_column($picks, 'external_id'))->get()->keyBy('external_id');
        $out = [];

        foreach ($picks as $pick) {
            $product = $products->get($pick['external_id']);

            if ($product !== null) {
                $out[] = ['external_id' => (string) $product->external_id, 'title' => (string) $product->title, 'url' => $product->url, 'image' => $product->image_url, 'why' => (string) $pick['why']];
            }
        }

        return $out;
    }

    /**
     * Every question asked is logged with what came of it; the reply carries its id, so what the
     * shopper does next (the WhatsApp offer, a pick) is added to it.
     *
     * @param  list<string>  $products
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function logged(string $shopId, string $question, array $products, array $result): array
    {
        $whatsapp = $result['outcome'] !== AssistantAnswer::ANSWERED && $result['outcome'] !== 'invalid'
            && Features::enabled('assistant.search_whatsapp', $shopId);

        if ($result['outcome'] === 'invalid') {
            unset($result['reason']);

            return $result;
        }

        $ask = AssistantSearchAsk::query()->create([
            'shop_id' => $shopId,
            'day' => now()->toDateString(),
            'question' => $question,
            'products' => $products,
            'outcome' => $result['outcome'],
            'from' => $result['from'],
            // Why it got no answer, for the operator: what refused it.
            'reason' => $result['reason'] ?? null,
            'answer_id' => $result['answer_id'] ?? null,
            'picks' => array_map(fn (array $p): array => ['external_id' => $p['external_id'], 'title' => $p['title'], 'why' => $p['why']], (array) ($result['picks'] ?? [])),
            'whatsapp_shown' => $whatsapp,
        ]);

        unset($result['answer_id'], $result['reason']);

        return $result + ['ask_id' => $ask->id, 'whatsapp' => $whatsapp];
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
    /**
     * The kind of question, from what code and the writer made of it before the checker: a
     * superlative, the store's own words (shipping, returns), products, or both. The checker's
     * record is read by it, to learn where a check is worth its cost.
     *
     * @param  list<string>  $cited
     * @param  list<array<string, mixed>>  $picks
     */
    public static function questionType(?string $asks, array $cited, array $picks): string
    {
        return match (true) {
            $asks !== null => 'superlative',
            $cited !== [] && $picks !== [] => 'mixed',
            $picks !== [] => 'products',
            $cited !== [] => 'store_info',
            default => 'none',
        };
    }

    private function call(RunContext $run, array $role, string $prompt, string $input, int $maxOutput, ?string $effort, string $prices): ModelReply
    {
        $reply = $this->models->json($role['provider'], $role['model'], self::prompt($prompt), $input, $maxOutput, $effort);
        $run->usage($role['name'], $role['model'], $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output')));

        return $reply;
    }

    private function withinDailyLimits(string $shopId, string $visitorHash): bool
    {
        // From a storefront request, its address counts too; from the console there is none.
        return DailyQuestions::allow($shopId, $visitorHash, app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip());
    }

    /**
     * @param  list<array{title: string, url: string|null, type: string}>  $sources
     * @param  list<array{external_id: string, title: string, why: string}>  $picks
     * @param  list<array{0: ModelReply, 1: string}>  $replies
     */
    private function save(string $question, string $outcome, ?string $answer, array $sources, array $picks, ?string $model, array $replies, RunContext $run): AssistantAnswer
    {
        $cost = 0.0;
        $input = 0;
        $output = 0;

        foreach ($replies as [$reply, $prices]) {
            $cost += $reply->costUsd($this->price($prices.'_input'), $this->price($prices.'_output'));
            $input += $reply->inputTokens;
            $output += $reply->outputTokens;
        }

        return AssistantAnswer::query()->create([
            'shop_id' => $this->tenant->require(),
            'question_key' => Question::key($question),
            'question' => $question,
            'answer' => $answer,
            'outcome' => $outcome,
            'source' => $answer === null && $picks === [] ? null : 'store',
            'sources' => $sources === [] ? null : $sources,
            'picks' => $picks === [] ? null : $picks,
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

    /** A number the pages, the products and the question do not have is one the model made up. */
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
