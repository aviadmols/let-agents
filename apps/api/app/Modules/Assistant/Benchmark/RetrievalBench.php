<?php

namespace App\Modules\Assistant\Benchmark;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\ChunkExperiments;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Runs\Contracts\RunContext;
use Illuminate\Support\Facades\DB;

/**
 * Where a wrong answer comes from: retrieval, facts, or the writer. Measured on the same questions
 * as the writer benchmark, so each of its errors can be put on one of the three.
 *
 *   1. pool     (production) for each question, what reached the writer (the products the search
 *               showed, the passages prepare() chose) and what a wider net finds (40 products by
 *               meaning, 40 whose names hold its words, 30 passages with no floor, and the
 *               passages pages cut at 500 / 1200 / 2000 characters would give). Built inside a
 *               transaction that is rolled back; the index is not touched.
 *   2. label    (production) one judge per question marks which of all those candidates answer it,
 *               and whether the store holds an answer at all. Each candidate is judged once,
 *               whichever net found it (pooling).
 *   3. report   recall@k of what reached the writer and of every wider net, the piece sizes side by
 *               side, and every wrong answer of the writer benchmark put on retrieval (nothing that
 *               answers reached the writer), facts (it reached the writer, the facts-only writer
 *               failed where the writer with the descriptions did not) or the writer.
 */
final class RetrievalBench
{
    public const JUDGE_PROMPT = __DIR__.'/../Prompts/retrieval_judge.v1.md';

    /** The piece sizes tried; 1200 is the index's own (retrieval.chunk_chars). */
    public const SIZES = [500, 1200, 2000];

    private const WIDE_PRODUCTS = 40;

    private const WIDE_PASSAGES = 30;

    private const CHUNK_TOP = 10;

    private const EXCERPT = 300;

    private const JUDGE_OUTPUT_TOKENS = 3000;

    /** Where each writer-benchmark answer lands; the first two and no_answer_refused are right. */
    public const LAYERS = ['correct', 'no_answer_refused', 'retrieval', 'facts', 'writer', 'no_answer_invented', 'failed_call'];

    public function __construct(
        private readonly AnswerSiteQuestion $answers,
        private readonly SemanticSearch $semantic,
        private readonly Passages $passages,
        private readonly ChunkExperiments $chunks,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
        private readonly TenantContext $tenant,
    ) {}

    // ------------------------------------------------------------------ 1. pool

    /** @return array<string, mixed>|null null when the question reaches no writer today */
    public function pool(AssistantSearchAsk $ask): ?array
    {
        // What reached the writer, built as the search box builds it, and every write of that building undone.
        DB::beginTransaction();
        try {
            $context = $this->answers->snapshot($ask->shop_id, $ask->question, (array) $ask->products);
        } finally {
            DB::rollBack();
        }
        if ($context['empty']) {
            return null;
        }
        $question = $context['question'];

        // The wider nets, outside that transaction: the pages cut again are embedded once per shop
        // and size, and that spending is accounted on runs of its own, which a rollback would erase.
        $found = $this->tenant->run($ask->shop_id, function () use ($ask, $question): array {
            $byMeaning = array_values(array_unique(array_map(fn (array $hit): string => (string) $hit['external_id'], $this->semantic->nearText($ask->shop_id, $question, ['product'], self::WIDE_PRODUCTS))));
            $words = Question::words($question);
            $byName = $words === [] ? [] : CatalogProduct::query()->active()
                ->where(function ($q) use ($words): void {
                    foreach ($words as $word) {
                        $q->orWhere('title', 'like', '%'.$word.'%');
                    }
                })
                ->orderByDesc('in_stock')->orderBy('id')->limit(self::WIDE_PRODUCTS)->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
            $wide = $this->passages->near($ask->shop_id, $question, ['product', 'content'], self::WIDE_PASSAGES);
            $sizes = [];
            foreach (self::SIZES as $size) {
                $rows = $this->chunks->near($ask->shop_id, $question, $size, ['product', 'content'], self::CHUNK_TOP);
                $sizes[$size] = ['docs' => array_values(array_unique(array_map(fn (array $r): string => self::docKey($r), $rows))), 'chars_top6' => array_sum(array_map(fn (array $r): int => mb_strlen($r['text']), array_slice($rows, 0, 6))), 'rows' => $rows];
            }

            return ['by_meaning' => $byMeaning, 'by_name' => $byName, 'wide' => $wide, 'sizes' => $sizes];
        });

        $production = [
            'products' => array_values(array_map(fn (array $p): string => 'product:'.$p['external_id'], $context['productsByRef'])),
            'docs' => array_values(array_unique(array_map(fn (array $p): string => self::docKey($p), $context['byRef']))),
        ];
        $candidates = [];
        foreach ($context['byRef'] as $p) {
            $candidates[self::docKey($p)] ??= ['title' => (string) $p['title'], 'text' => mb_substr((string) $p['text'], 0, self::EXCERPT)];
        }
        foreach ([...$found['wide'], ...array_merge(...array_map(fn (array $s): array => $s['rows'], array_values($found['sizes'])))] as $row) {
            $candidates[self::docKey($row)] ??= ['title' => (string) $row['title'], 'text' => mb_substr((string) $row['text'], 0, self::EXCERPT)];
        }
        // Every product any net found, described by its name, category and the start of its description.
        $productIds = array_values(array_unique([
            ...array_map(fn (string $k): string => substr($k, 8), $production['products']),
            ...$found['by_meaning'], ...$found['by_name'],
        ]));
        $this->tenant->run($ask->shop_id, function () use ($productIds, &$candidates): void {
            foreach (CatalogProduct::query()->whereIn('external_id', $productIds)->get() as $product) {
                $candidates['product:'.$product->external_id] = [
                    'title' => (string) $product->title,
                    'text' => mb_substr(trim(implode(' · ', array_filter([implode(' > ', $product->categoryPaths()[0] ?? []), strip_tags((string) $product->shortDescription())]))), 0, self::EXCERPT),
                ];
            }
        });

        return [
            'id' => $ask->id,
            'shop_id' => $ask->shop_id,
            'question' => $question,
            'language' => WriterBench::language($question),
            'production' => $production,
            'nets' => [
                'products_by_meaning' => array_map(fn (string $id): string => 'product:'.$id, $found['by_meaning']),
                'products_by_name' => array_map(fn (string $id): string => 'product:'.$id, $found['by_name']),
                'passages_wide' => array_values(array_unique(array_map(fn (array $r): string => self::docKey($r), $found['wide']))),
            ],
            'sizes' => array_map(fn (array $s): array => ['docs' => $s['docs'], 'chars_top6' => $s['chars_top6']], $found['sizes']),
            'candidates' => $candidates,
        ];
    }

    // ------------------------------------------------------------------ 2. label

    /** @return array<string, mixed> the record with the judge's labels */
    public function label(array $record, RunContext $run): array
    {
        $provider = AiProviderName::tryFrom(strtolower(trim((string) Settings::get('assistant.bench_judge_provider'))));
        $model = (string) Settings::get('assistant.bench_judge_model');
        if ($provider === null) {
            throw new \RuntimeException('No judge provider.');
        }

        // The candidates in an order of their own, so no net's order tells the judge anything.
        $keys = array_keys($record['candidates']);
        usort($keys, fn (string $a, string $b): int => strcmp(hash('sha256', $record['id'].$a), hash('sha256', $record['id'].$b)));
        $input = (string) json_encode([
            'question' => $record['question'],
            'candidates' => array_map(fn (string $k): array => ['key' => $k] + $record['candidates'][$k], $keys),
        ], JSON_UNESCAPED_UNICODE);

        $prices = [(float) Settings::get('assistant.bench_judge_input_usd_per_million'), (float) Settings::get('assistant.bench_judge_output_usd_per_million')];
        $this->spend->assertCanSpend(((int) ceil(mb_strlen($input) / 2) + 1000) * $prices[0] / 1e6 + self::JUDGE_OUTPUT_TOKENS * $prices[1] / 1e6);
        $reply = $this->models->json($provider, $model, (string) file_get_contents(self::JUDGE_PROMPT), $input, self::JUDGE_OUTPUT_TOKENS, null);
        $cost = $reply->costUsd(...$prices);
        $run->usage($provider->value, $model, $reply->inputTokens, $reply->outputTokens, 0, $cost);

        $relevant = array_values(array_intersect(array_map('strval', (array) ($reply->data['relevant'] ?? [])), $keys));
        $record['labels'] = [
            'answerable_in_store' => ($reply->data['answerable_in_store'] ?? null) === true && $relevant !== [],
            'relevant' => $relevant,
            'note' => mb_substr((string) ($reply->data['note'] ?? ''), 0, 300),
            'model' => $model,
            'cost_usd' => round($cost, 6),
        ];

        return $record;
    }

    // ------------------------------------------------------------------ 3. report

    /**
     * @param  list<array<string, mixed>>  $records  labelled
     * @param  array<string, array<string, mixed>>  $judged  the writer benchmark's judged records, by id
     * @return array<string, mixed>
     */
    public static function report(array $records, array $judged = []): array
    {
        $done = array_values(array_filter($records, fn (array $r): bool => isset($r['labels'])));
        $answerable = array_values(array_filter($done, fn (array $r): bool => $r['labels']['answerable_in_store']));
        // Recall is measured only on the questions the store can answer: one with no source in the store
        // cannot be retrieved by any net, and would only pull every recall down.
        $out = ['questions' => count($done), 'answerable_in_store' => count($answerable), 'not_answerable_in_store' => count($done) - count($answerable), 'recall_over' => 'answerable_in_store'];

        // Products: what the search showed, and the wider nets at several k.
        $products = fn (array $r): array => array_values(array_filter($r['labels']['relevant'], fn (string $k): bool => str_starts_with($k, 'product:')));
        $withProduct = array_values(array_filter($answerable, fn (array $r): bool => $products($r) !== []));
        $out['products'] = [
            'questions_with_a_relevant_product' => count($withProduct),
            'production_recall@shown' => self::recall($withProduct, $products, fn (array $r): array => $r['production']['products']),
            'production_hit@shown' => self::hit($withProduct, $products, fn (array $r): array => $r['production']['products']),
        ];
        foreach ([5, 12, 24, 40] as $k) {
            $out['products']["by_meaning_recall@{$k}"] = self::recall($withProduct, $products, fn (array $r): array => array_slice($r['nets']['products_by_meaning'], 0, $k));
        }
        // The names net has no order of its own (in stock first, then by id): only its whole 40 says anything.
        $out['products']['by_name_recall@40'] = self::recall($withProduct, $products, fn (array $r): array => $r['nets']['products_by_name']);
        $out['products']['any_net_recall'] = self::recall($withProduct, $products, fn (array $r): array => [...$r['production']['products'], ...$r['nets']['products_by_meaning'], ...$r['nets']['products_by_name']]);

        // Passages: by document, what prepare() gave, and each piece size.
        $pages = fn (array $r): array => array_values(array_filter($r['labels']['relevant'], fn (string $k): bool => ! str_starts_with($k, 'product:')));
        $withDoc = array_values(array_filter($answerable, fn (array $r): bool => $pages($r) !== []));
        $out['pages'] = [
            'questions_with_a_relevant_page' => count($withDoc),
            'production_recall' => self::recall($withDoc, $pages, fn (array $r): array => $r['production']['docs']),
            'production_hit' => self::hit($withDoc, $pages, fn (array $r): array => $r['production']['docs']),
            'wide_recall@30' => self::recall($withDoc, $pages, fn (array $r): array => $r['nets']['passages_wide']),
        ];
        foreach (self::SIZES as $size) {
            foreach ([3, 6, 10] as $k) {
                $out['pages']["size_{$size}_recall@{$k}"] = self::recall($withDoc, $pages, fn (array $r): array => array_slice($r['sizes'][$size]['docs'] ?? [], 0, $k));
            }
            $chars = array_map(fn (array $r): int => (int) ($r['sizes'][$size]['chars_top6'] ?? 0), $done);
            $out['pages']["size_{$size}_avg_chars_top6"] = $chars === [] ? 0 : (int) round(array_sum($chars) / count($chars));
        }

        // Every wrong answer of the writer benchmark, put on one layer.
        if ($judged !== []) {
            $out['attribution'] = self::attribution($done, $judged);
        }

        return $out;
    }

    /**
     * retrieval: the store holds an answer and none of it reached the writer.
     * facts: it reached the writer; the facts-only writer failed where the writer with the
     *        descriptions (the current model, v4) did not, so the answer was in free text only.
     * writer: what answers was in front of it, and the writer missed, invented or misplaced it.
     * On a question the store holds no answer to, apart: no_answer_refused (it showed nothing, the
     * right behaviour) or no_answer_invented (it showed an answer or products: a writer error).
     *
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, array<string, mixed>>  $judged
     * @return array<string, array<string, int>>
     */
    private static function attribution(array $records, array $judged): array
    {
        $out = [];
        foreach ($records as $r) {
            $j = $judged[$r['id']] ?? null;
            if ($j === null || ! isset($j['judge'])) {
                continue;
            }
            $reached = array_merge($r['production']['products'], $r['production']['docs']);
            $retrievalMiss = $r['labels']['answerable_in_store'] && array_intersect($r['labels']['relevant'], $reached) === [];
            $currentRight = isset($j['writers'][WriterBench::CURRENT]) && ! isset($j['writers'][WriterBench::CURRENT]['error']) && WriterBench::finalCorrect($j, WriterBench::CURRENT);

            foreach ($j['writers'] as $key => $side) {
                $out[$key] ??= array_fill_keys(self::LAYERS, 0);
                $layer = match (true) {
                    isset($side['error']) => 'failed_call',
                    ! $r['labels']['answerable_in_store'] => WriterBench::shown($j, $key) ? 'no_answer_invented' : 'no_answer_refused',
                    WriterBench::finalCorrect($j, $key) => 'correct',
                    $retrievalMiss => 'retrieval',
                    str_ends_with($key, '@'.WriterBench::V5) && $currentRight => 'facts',
                    default => 'writer',
                };
                $out[$key][$layer]++;
            }
        }

        return $out;
    }

    /**
     * Mean over the questions of the share of their relevant items a list holds.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private static function recall(array $records, callable $relevant, callable $list): float
    {
        if ($records === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($records as $r) {
            $wanted = $relevant($r);
            $sum += $wanted === [] ? 0 : count(array_intersect($wanted, $list($r))) / count($wanted);
        }

        return round(100 * $sum / count($records), 1);
    }

    /** The share of questions where a list holds at least one relevant item. */
    private static function hit(array $records, callable $relevant, callable $list): float
    {
        if ($records === []) {
            return 0.0;
        }

        return round(100 * count(array_filter($records, fn (array $r): bool => array_intersect($relevant($r), $list($r)) !== [])) / count($records), 1);
    }

    /**
     * A product's passage is the product itself; a page is its page, by its title, since the index,
     * the title search and the pieces cut again name a page by different ids; each of the store's
     * own statements is one document.
     */
    private static function docKey(array $passage): string
    {
        return match (true) {
            ($passage['kind'] ?? null) === 'knowledge' => 'knowledge:'.$passage['source_id'],
            ($passage['source'] ?? null) === 'product' => 'product:'.$passage['external_id'],
            default => 'content:'.mb_strtolower(trim((string) $passage['title'])),
        };
    }
}
