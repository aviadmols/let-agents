<?php

namespace App\Modules\Assistant\Benchmark;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Contracts\PriceRange;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\AnswerContext;
use App\Modules\Runs\Contracts\RunContext;
use Illuminate\Support\Facades\DB;

/**
 * The search box's writer against other writers, on questions shoppers asked, with the same input.
 *
 *   1. snapshot   (production) the question's context, built now by the same prepare() inside a
 *                 transaction that is rolled back, so nothing is written (not even a vector's use);
 *                 the current writer and the checker run on it. Nothing is shown or saved for shoppers.
 *   2. write      (anywhere) another writer on that very input (LocalWriter: Ollama or OpenRouter),
 *                 through the same code gate. Once per model; each is kept under its name.
 *   3. judge      (production) the checker on every other writer's reply, then one blind judge
 *                 that reads all the replies of a question in a shuffled order; then the summary.
 *
 * Each step reads and writes one JSON line per question, so a stopped run goes on where it was.
 */
final class WriterBench
{
    public const CURRENT = 'current';

    public const JUDGE_PROMPT = __DIR__.'/../Prompts/writer_judge.v1.md';

    private const JUDGE_OUTPUT_TOKENS = 3000;

    /** What the judge calls the replies, in the shuffled order it reads them. */
    private const LETTERS = ['A', 'B', 'C', 'D', 'E'];

    public function __construct(
        private readonly AnswerSiteQuestion $answers,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    // ------------------------------------------------------------------ 1. snapshot

    /** @return array<string, mixed>|null null when the question would reach no writer today */
    public function snapshot(AssistantSearchAsk $ask, RunContext $run): ?array
    {
        // Built as the search box builds it, and every write of that building undone.
        DB::beginTransaction();
        try {
            $context = $this->answers->snapshot($ask->shop_id, $ask->question, (array) $ask->products);
        } finally {
            DB::rollBack();
        }

        if ($context['empty']) {
            return null;
        }

        $record = [
            'id' => $ask->id,
            'shop_id' => $ask->shop_id,
            'asked_at' => $ask->created_at?->toIso8601String(),
            'question' => $context['question'],
            'language' => self::language($context['question']),
            'original' => ['outcome' => $ask->outcome, 'picks' => array_column((array) $ask->picks, 'external_id')],
            'context' => array_intersect_key($context, array_flip(['mode', 'type', 'input', 'byRef', 'productsByRef', 'forModel', 'analysis']))
                + ['constraints' => AnswerContext::constraints($context['question']), 'max_output' => (int) Settings::get('assistant.answer_max_output_tokens')],
        ];

        $writer = AgentModel::from('assistant.answer_provider', 'assistant.answer_model');
        $effort = (string) Settings::get('assistant.reasoning_effort');
        $current = $this->current($record, $run, $writer->provider, $writer->model, $effort === 'model_default' ? null : $effort);
        $current['check'] = $this->check($record, $current, $run);
        $record['writers'] = [self::CURRENT => $current];

        return $record;
    }

    /** @return array<string, mixed> */
    private function current(array $record, RunContext $run, AiProviderName $provider, string $model, ?string $effort): array
    {
        $input = $record['context']['input'];
        $prices = [(float) Settings::get('assistant.answer_input_usd_per_million'), (float) Settings::get('assistant.answer_output_usd_per_million')];
        $max = $record['context']['max_output'];
        $this->spend->assertCanSpend(((int) ceil(mb_strlen($input) / 2) + 600) * $prices[0] / 1e6 + $max * $prices[1] / 1e6);
        $started = hrtime(true);

        try {
            $reply = $this->models->json($provider, $model, AnswerSiteQuestion::prompt('answer_site'), $input, $max, $effort);
        } catch (ModelCallFailed $e) {
            return ['model' => $model, 'error' => $e->reason, 'latency_ms' => (int) round((hrtime(true) - $started) / 1e6)];
        }

        $cost = $reply->costUsd(...$prices);
        $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $cost);

        return $this->screened($record, $reply->data) + [
            'model' => $model,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'tokens' => ['in' => $reply->inputTokens, 'out' => $reply->outputTokens],
            'cost_usd' => round($cost, 6),
        ];
    }

    /**
     * The search box's checker on one writer's reply, as the search box runs it.
     *
     * @param  array<string, mixed>  $side
     * @return array<string, mixed> verdict: kept, answer_removed, picks_removed, rejected, skipped or error
     */
    public function check(array $record, array $side, RunContext $run): array
    {
        if (isset($side['error'])) {
            return ['verdict' => 'error'];
        }
        if (($side['raw']['found'] ?? null) !== true || ($side['answer'] === '' && $side['picks_screened'] === [])) {
            return ['verdict' => 'skipped', 'reason' => $side['refusal'] ?? 'not_found', 'cost_usd' => 0];
        }

        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('assistant.site_check_provider'))));
        $model = (string) Settings::get('assistant.site_check_model');
        $prices = [(float) Settings::get('assistant.site_check_input_usd_per_million'), (float) Settings::get('assistant.site_check_output_usd_per_million')];
        $max = (int) Settings::get('assistant.site_check_max_output_tokens');

        if ($provider === null) {
            return ['verdict' => 'error', 'reason' => 'no_checker'];
        }

        $input = $this->answers->checkInput($record['question'], $side['answer'], $side['cited'], $side['picks_screened'], $this->prepared($record));
        $this->spend->assertCanSpend(((int) ceil(mb_strlen($input) / 2) + 600) * $prices[0] / 1e6 + $max * $prices[1] / 1e6);
        $started = hrtime(true);

        try {
            $reply = $this->models->json($provider, $model, AnswerSiteQuestion::prompt('verify_site'), $input, $max, null);
        } catch (ModelCallFailed $e) {
            return ['verdict' => 'error', 'reason' => $e->reason];
        }

        $cost = $reply->costUsd(...$prices);
        $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $cost);
        $applied = AnswerSiteQuestion::applyCheck($reply->data, $side['answer'], $side['picks_screened']);

        return ['verdict' => $applied['verdict'], 'answer_kept' => $applied['answer'] !== '', 'picks_kept' => $applied['picks'] !== [],
            'model' => $model, 'cost_usd' => round($cost, 6), 'latency_ms' => (int) round((hrtime(true) - $started) / 1e6), 'data' => $reply->data];
    }

    // ------------------------------------------------------------------ 2. another writer

    /** @return array<string, mixed> one writer's side of a record */
    public function write(array $record, LocalWriter $writer): array
    {
        $started = hrtime(true);

        try {
            $reply = $writer->write(AnswerSiteQuestion::prompt('answer_site'), $record['context']['input'], (int) ($record['context']['max_output'] ?? 1500));
        } catch (ModelCallFailed $e) {
            return ['model' => LocalWriter::model(), 'error' => $e->reason, 'detail' => mb_substr($e->getMessage(), 0, 300), 'latency_ms' => (int) round((hrtime(true) - $started) / 1e6)];
        }

        return $this->screened($record, $reply->data) + [
            'model' => LocalWriter::model(),
            'latency_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'tokens' => ['in' => $reply->inputTokens, 'out' => $reply->outputTokens],
            'cost_usd' => isset($writer->usage['cost']) ? round((float) $writer->usage['cost'], 6) : null,
            'provider' => $writer->usage['provider'] ?? null,
        ];
    }

    /**
     * A writer's reply through the search box's own gate, and code's measures of it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function screened(array $record, array $data): array
    {
        $screen = $this->answers->screen($data, $this->prepared($record), $record['question'], $record['context']['mode']);

        return [
            'raw' => $data,
            'answer' => $screen['answer'],
            'refusal' => $screen['refusal'],
            'cited' => $screen['cited'],
            'picks_screened' => $screen['picks'],
            'picks' => array_column($screen['picks'], 'external_id'),
            'code' => $this->measures($record, $data, $screen),
        ];
    }

    /**
     * What code can say about a reply without a model: picks not offered, out of stock while
     * something in stock was there, outside the price asked, of another brand than asked, not in
     * price order for "the cheapest", a number or a price written, internal refs ("p5") or letters
     * of another script shown to the shopper, and the answer's language.
     *
     * @param  array<string, mixed>  $data
     * @param  array{answer: string, refusal: string|null, picks: list<array<string, mixed>>}  $screen
     * @return array<string, mixed>
     */
    private function measures(array $record, array $data, array $screen): array
    {
        $products = $record['context']['productsByRef'];
        $analysis = $record['context']['analysis'];
        $rawRefs = array_map(fn ($p): string => is_array($p) ? (string) ($p['ref'] ?? '') : '', (array) ($data['picks'] ?? []));
        $picked = array_map(fn (array $p): array => $products[$p['ref']], $screen['picks']);
        $ranks = array_flip((array) ($analysis['in_stock_cheapest_first'] ?? []));
        $anyInStock = array_filter($products, fn (array $p): bool => (bool) $p['in_stock']) !== [];
        ['min' => $min, 'max' => $max] = app()->bound(PriceRange::class) ? app(PriceRange::class)->read($record['question']) : ['min' => null, 'max' => null];
        $question = mb_strtolower($record['question']);
        $brandsAsked = array_values(array_filter((array) ($analysis['brands'] ?? []), fn ($b): bool => is_string($b) && $b !== '' && str_contains($question, mb_strtolower($b))));
        $pickRanks = array_map(fn (array $p): ?int => $ranks[$p['ref']] ?? null, $screen['picks']);
        $answerText = (string) ($data['answer'] ?? '');
        $shown = $answerText.' '.implode(' ', array_map(fn ($p): string => is_array($p) ? (string) ($p['why'] ?? '') : '', (array) ($data['picks'] ?? [])));
        preg_match_all('~\b[ps]\d{1,2}\b~u', $shown, $refs);
        preg_match_all('~[\x{0600}-\x{06FF}\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}]+~u', $shown, $foreign);

        return [
            'invalid_picks' => count(array_filter($rawRefs, fn (string $ref): bool => ! isset($products[$ref]))),
            'out_of_stock_picks' => $anyInStock ? count(array_filter($picked, fn (array $p): bool => ! $p['in_stock'])) : 0,
            'outside_price' => count(array_filter($picked, fn (array $p): bool => $p['price'] !== null && (($min !== null && $p['price'] < $min) || ($max !== null && $p['price'] > $max)))),
            'other_brand' => $brandsAsked === [] ? 0 : count(array_filter($picked, fn (array $p): bool => ! in_array(mb_strtolower((string) ($p['brand'] ?? '')), array_map('mb_strtolower', $brandsAsked), true))),
            'price_order' => match ($analysis['asks'] ?? null) {
                'cheapest' => self::ordered($pickRanks, 1),
                'most_expensive' => self::ordered($pickRanks, -1),
                default => true,
            },
            'invented_number' => $screen['refusal'] === 'number_not_in_the_pages',
            'wrote_price' => $screen['refusal'] === 'price',
            'internal_ids' => array_values(array_unique($refs[0])),
            'foreign_script' => array_values(array_unique($foreign[0])),
            'answer_language' => $answerText === '' ? null : self::language($answerText),
            'language_ok' => $answerText === '' || self::sameScript($record['question'], $answerText),
        ];
    }

    // ------------------------------------------------------------------ 3. checker and judge

    /**
     * The checker on every writer that has not met it, then one blind judge for all of them.
     *
     * @return array<string, mixed> the record, judged
     */
    public function judge(array $record, RunContext $run): array
    {
        foreach ($record['writers'] as $key => $side) {
            if (! isset($side['check'])) {
                $record['writers'][$key]['check'] = $this->check($record, $side, $run);
            }
        }

        if (isset($record['judge'])) {
            return $record;
        }

        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('assistant.bench_judge_provider'))));
        $model = (string) Settings::get('assistant.bench_judge_model');

        // The judge is of the current writer's family by no means; the other writers are not providers here at all.
        if ($provider === null || $provider->family() === AgentModel::provider('assistant.answer_provider')->family()) {
            throw new \RuntimeException('The judge must come from another family than the current writer.');
        }

        // Blind: the order is decided by the question, so a rerun gives the same order.
        $keys = array_keys(array_filter($record['writers'], fn (array $side): bool => ! isset($side['error'])));
        usort($keys, fn (string $a, string $b): int => strcmp(hash('sha256', $record['id'].$a), hash('sha256', $record['id'].$b)));
        $letters = array_combine(array_slice(self::LETTERS, 0, count($keys)), $keys);

        $input = (string) json_encode([
            'question' => $record['question'],
            'products' => array_map(fn (array $entry): array => $entry + array_filter(['price' => $record['context']['productsByRef'][$entry['ref']]['price'] ?? null]), $record['context']['forModel']),
            'analysis' => $record['context']['analysis'],
            'passages' => array_map(fn (string $ref, array $p): array => ['ref' => $ref, 'title' => $p['title'], 'text' => $p['text']], array_keys($record['context']['byRef']), $record['context']['byRef']),
            'replies' => array_map(fn (string $key): array => $this->forJudge($record, $record['writers'][$key]), $letters),
        ], JSON_UNESCAPED_UNICODE);

        $prices = [(float) Settings::get('assistant.bench_judge_input_usd_per_million'), (float) Settings::get('assistant.bench_judge_output_usd_per_million')];
        $this->spend->assertCanSpend(((int) ceil(mb_strlen($input) / 2) + 1500) * $prices[0] / 1e6 + self::JUDGE_OUTPUT_TOKENS * $prices[1] / 1e6);
        $reply = $this->models->json($provider, $model, (string) file_get_contents(self::JUDGE_PROMPT), $input, self::JUDGE_OUTPUT_TOKENS, null);
        $cost = $reply->costUsd(...$prices);
        $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $cost);

        $verdicts = [];
        foreach ($letters as $letter => $key) {
            $verdicts[$key] = (array) ($reply->data['replies'][$letter] ?? []);
        }
        $record['judge'] = [
            'model' => $model,
            'cost_usd' => round($cost, 6),
            'order' => $letters,
            'answerable' => ($reply->data['answerable'] ?? null) === true,
            'answerable_note' => (string) ($reply->data['answerable_note'] ?? ''),
            'best' => isset($reply->data['best'], $letters[$reply->data['best']]) ? $letters[$reply->data['best']] : ($reply->data['best'] ?? null),
            'reason' => (string) ($reply->data['reason'] ?? ''),
            'verdicts' => $verdicts,
        ];

        return $record;
    }

    /** One writer's reply as the judge reads it: what it wrote, before code's gate, with each pick named. */
    private function forJudge(array $record, array $side): array
    {
        $products = $record['context']['productsByRef'];

        return [
            'found' => $side['raw']['found'] ?? null,
            'answer' => (string) ($side['raw']['answer'] ?? ''),
            'picks' => array_values(array_map(fn ($p): array => [
                'ref' => (string) ($p['ref'] ?? ''),
                'title' => $products[(string) ($p['ref'] ?? '')]['title'] ?? '(not in the products given)',
                'why' => (string) ($p['why'] ?? ''),
            ], array_filter((array) ($side['raw']['picks'] ?? []), 'is_array'))),
        ];
    }

    // ------------------------------------------------------------------ summary

    /**
     * Per writer: what it picked, what it missed, made up or put on the wrong item, what the
     * checker did, what a shopper would finally see and whether that is right, and what it cost.
     *
     * @param  list<array<string, mixed>>  $records  judged
     * @return array<string, mixed>
     */
    public static function summary(array $records): array
    {
        $done = array_values(array_filter($records, fn (array $r): bool => isset($r['judge'])));
        $keys = [];
        foreach ($done as $r) {
            $keys = array_values(array_unique([...$keys, ...array_keys($r['writers'])]));
        }
        $out = ['questions' => count($done), 'answerable' => count(array_filter($done, fn (array $r): bool => $r['judge']['answerable'])), 'writers' => []];

        foreach ($keys as $key) {
            $rows = array_values(array_filter($done, fn (array $r): bool => isset($r['writers'][$key])));
            $ok = array_values(array_filter($rows, fn (array $r): bool => ! isset($r['writers'][$key]['error'])));
            $answerable = array_values(array_filter($ok, fn (array $r): bool => $r['judge']['answerable']));
            $count = fn (callable $test, array $of): int => count(array_filter($of, $test));
            $latency = array_values(array_map(fn (array $r): int => (int) $r['writers'][$key]['latency_ms'], $ok));
            sort($latency);
            $writerCost = array_sum(array_map(fn (array $r): float => (float) ($r['writers'][$key]['cost_usd'] ?? 0), $ok));
            $checkerCost = array_sum(array_map(fn (array $r): float => (float) ($r['writers'][$key]['check']['cost_usd'] ?? 0), $ok));
            $byLanguage = [];
            foreach (['he', 'en', 'mixed'] as $language) {
                $of = array_values(array_filter($ok, fn (array $r): bool => $r['language'] === $language));
                $byLanguage[$language] = ['questions' => count($of), 'final_accuracy_pct' => self::pct($count(fn (array $r): bool => self::finalCorrect($r, $key), $of), count($of))];
            }

            $out['writers'][$key] = [
                'model' => $ok[0]['writers'][$key]['model'] ?? $key,
                'questions' => count($rows),
                'failures' => count($rows) - count($ok),
                'correct_product_selection_pct' => self::pct($count(fn (array $r): bool => self::picksOk($r, $key), $ok), count($ok)),
                'missed_answer_pct' => self::pct($count(fn (array $r): bool => self::missed($r, $key), $answerable), count($answerable)),
                'hallucination_pct' => self::pct($count(fn (array $r): bool => self::hallucinated($r, $key), $ok), count($ok)),
                'wrong_attribution_pct' => self::pct($count(fn (array $r): bool => ($r['judge']['verdicts'][$key]['wrong_attribution'] ?? false) === true, $ok), count($ok)),
                'internal_id_leak_pct' => self::pct($count(fn (array $r): bool => $r['writers'][$key]['code']['internal_ids'] !== [], $ok), count($ok)),
                'language_issue_pct' => self::pct($count(fn (array $r): bool => ! self::languageOk($r, $key), $ok), count($ok)),
                'checker_intervention_pct' => self::pct($count(fn (array $r): bool => in_array($r['writers'][$key]['check']['verdict'] ?? null, ['answer_removed', 'picks_removed', 'rejected'], true), $ok), count($ok)),
                'checker_rejection_pct' => self::pct($count(fn (array $r): bool => ($r['writers'][$key]['check']['verdict'] ?? null) === 'rejected', $ok), count($ok)),
                'checker_verdicts' => array_count_values(array_map(fn (array $r): string => (string) ($r['writers'][$key]['check']['verdict'] ?? 'none'), $ok)),
                'raw_accuracy_pct' => self::pct($count(fn (array $r): bool => self::rawCorrect($r, $key), $ok), count($ok)),
                'final_accuracy_after_checker_pct' => self::pct($count(fn (array $r): bool => self::finalCorrect($r, $key), $ok), count($ok)),
                'final_accuracy_by_language' => $byLanguage,
                'best_by_judge' => $count(fn (array $r): bool => ($r['judge']['best'] ?? null) === $key, $done),
                'latency_ms' => $latency === [] ? null : [
                    'avg' => (int) round(array_sum($latency) / count($latency)),
                    'p50' => $latency[(int) floor((count($latency) - 1) * 0.5)],
                    'p95' => $latency[(int) floor((count($latency) - 1) * 0.95)],
                ],
                'cost_per_question_usd' => $ok === [] ? null : [
                    'writer' => round($writerCost / count($ok), 6),
                    'checker' => round($checkerCost / count($ok), 6),
                    'total' => round(($writerCost + $checkerCost) / count($ok), 6),
                ],
            ];
        }

        $out['judge_cost_usd'] = round(array_sum(array_map(fn (array $r): float => (float) ($r['judge']['cost_usd'] ?? 0), $done)), 4);

        return $out;
    }

    // ------------------------------------------------------------------ measures

    /** he, en or mixed: a Hebrew sentence with an English brand or model is mixed. */
    public static function language(string $text): string
    {
        $hebrew = preg_match_all('/\p{Hebrew}/u', $text);
        $latinWords = preg_match_all('/[A-Za-z]{2,}/u', $text);

        return match (true) {
            $hebrew > 0 && $latinWords > 0 => 'mixed',
            $hebrew > 0 => 'he',
            default => 'en',
        };
    }

    /** The answer is written mostly in the question's own script. */
    private static function sameScript(string $question, string $answer): bool
    {
        $mostlyHebrew = fn (string $s): bool => preg_match_all('/\p{Hebrew}/u', $s) >= preg_match_all('/[A-Za-z]/u', $s);

        return $mostlyHebrew($question) === $mostlyHebrew($answer);
    }

    private static function verdict(array $record, string $key): array
    {
        return (array) ($record['judge']['verdicts'][$key] ?? []);
    }

    /** The picks are right by the judge and break none of code's rules. */
    private static function picksOk(array $record, string $key): bool
    {
        $code = $record['writers'][$key]['code'] ?? [];

        return (self::verdict($record, $key)['picks_correct'] ?? false) === true && ($code['invalid_picks'] ?? 0) === 0 && ($code['out_of_stock_picks'] ?? 0) === 0
            && ($code['outside_price'] ?? 0) === 0 && ($code['other_brand'] ?? 0) === 0 && ($code['price_order'] ?? true) === true;
    }

    /** The answer, as written, states only what is true of what it is about. */
    private static function answerOk(array $record, string $key): bool
    {
        $v = self::verdict($record, $key);
        $code = $record['writers'][$key]['code'] ?? [];

        return ($v['answer_correct'] ?? false) === true && ($v['hallucination'] ?? false) !== true && ($v['wrong_attribution'] ?? false) !== true
            && $code['internal_ids'] === [] && $code['foreign_script'] === [] && self::languageOk($record, $key);
    }

    /** The input answers the question, and the writer gave neither an answer nor a pick. */
    private static function missed(array $record, string $key): bool
    {
        $raw = $record['writers'][$key]['raw'] ?? [];

        return ($raw['found'] ?? false) !== true || (trim((string) ($raw['answer'] ?? '')) === '' && ((array) ($raw['picks'] ?? [])) === []);
    }

    private static function hallucinated(array $record, string $key): bool
    {
        $code = $record['writers'][$key]['code'] ?? [];

        return (self::verdict($record, $key)['hallucination'] ?? false) === true || ($code['invented_number'] ?? false) || ($code['wrote_price'] ?? false) || ($code['invalid_picks'] ?? 0) > 0;
    }

    private static function languageOk(array $record, string $key): bool
    {
        return ($record['writers'][$key]['code']['language_ok'] ?? true) && (self::verdict($record, $key)['language_correct'] ?? true) === true;
    }

    /** What the writer wrote, before code and the checker, is right to show. */
    private static function rawCorrect(array $record, string $key): bool
    {
        if (! $record['judge']['answerable']) {
            return self::missed($record, $key) || (self::answerOk($record, $key) && self::picksOk($record, $key));
        }

        return ! self::missed($record, $key) && self::answerOk($record, $key) && self::picksOk($record, $key);
    }

    /**
     * What a shopper finally sees, after code's gate and the checker, is right: nothing shown is
     * right only when the input does not answer the question; what is shown must be correct.
     */
    private static function finalCorrect(array $record, string $key): bool
    {
        $side = $record['writers'][$key];
        $check = $side['check'] ?? [];
        $answerShown = $side['answer'] !== '' && in_array($check['verdict'] ?? null, ['kept', 'picks_removed'], true);
        $picksShown = $side['picks'] !== [] && in_array($check['verdict'] ?? null, ['kept', 'answer_removed'], true);

        if (! $answerShown && ! $picksShown) {
            return ! $record['judge']['answerable'];
        }

        return (! $answerShown || self::answerOk($record, $key)) && (! $picksShown || self::picksOk($record, $key));
    }

    /** @param  list<int|null>  $ranks */
    private static function ordered(array $ranks, int $direction): bool
    {
        $ranks = array_values(array_filter($ranks, fn (?int $r): bool => $r !== null));
        for ($i = 1; $i < count($ranks); $i++) {
            if (($ranks[$i] - $ranks[$i - 1]) * $direction < 0) {
                return false;
            }
        }

        return true;
    }

    private static function pct(int $part, int $of): float
    {
        return $of === 0 ? 0.0 : round(100 * $part / $of, 1);
    }

    /** The context as screen() and checkInput() read it. */
    private function prepared(array $record): array
    {
        return array_intersect_key($record['context'], array_flip(['byRef', 'productsByRef', 'forModel', 'analysis']));
    }
}
