<?php

namespace App\Modules\Retrieval\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\Candidate;
use App\Modules\Retrieval\Contracts\CandidateSource;
use App\Modules\Retrieval\Enums\MatchKind;
use App\Modules\Retrieval\Enums\MatchRequestStatus;
use App\Modules\Retrieval\Enums\MatchStatus;
use App\Modules\Retrieval\Models\RetrievalMatch;
use App\Modules\Retrieval\Models\RetrievalMatchRequest;
use App\Modules\Retrieval\Sources\ProductDocuments;
use App\Modules\Retrieval\Support\MatchCheck;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Retrieval\Support\Purchases;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What to show with each product, chosen by a model from what code found, and checked by code.
 *
 *   1. find     every CandidateSource tagged retrieval.candidates offers products for this one:
 *               bought together (with lift), similar in meaning (the index), named together in
 *               the store's guides. Only products in stock and for sale are offered.
 *   2. skip     a product whose candidates and text are what they were last time keeps last
 *               time's answer: the input hash says so, and the model is not asked again
 *   3. choose   the model the settings name picks complements and alternatives by ref, with a
 *               few words of why, from those candidates only
 *   4. check    MatchCheck refuses anything invented, unavailable, of both kinds, an alternative
 *               that is not similar, or past the shop's number. Refusals are kept, with why.
 *
 * Accepted picks are read by Enrichment as one more source of relations, next to the merchant's
 * links and what was bought together; nothing here writes a relation itself.
 *
 * Best sellers are asked about first: that is where a good suggestion is seen most. Matching
 * may use only part of the monthly AI cap (`retrieval.match_max_share_of_cap`), so the shopper
 * assistant is never left without budget by a night of matching.
 */
final class MatchProducts
{
    public const AGENT = 'retrieval.matcher';

    public const ACTION = 'retrieval.match_products';

    public const PROMPT_VERSION = 1;

    private const TEXT_CHARS = 1500;

    private const CHARS_PER_TOKEN = 2;

    private const SAMPLES = 12;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /** @param list<string>|null $productIds only these products, asked again even if nothing changed (from the panel) */
    public function handle(string $shopId, ?array $productIds = null): Run
    {
        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: $productIds === null ? [] : ['products' => $productIds],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->match($run, $shopId, $productIds)),
        );
    }

    public static function prompt(int $complements, int $alternatives): string
    {
        return strtr((string) file_get_contents(__DIR__.'/../Prompts/match.v'.self::PROMPT_VERSION.'.md'), [
            '{complements}' => (string) $complements,
            '{alternatives}' => (string) $alternatives,
        ]);
    }

    /** @param list<string>|null $only */
    private function match(RunContext $run, string $shopId, ?array $only): void
    {
        if (! Features::enabled('retrieval.ai_matching', $shopId)) {
            $run->output(['stopped' => 'off'])->summary('retrieval::runs.matching_off');

            return;
        }

        $choice = ModelChoice::for('match');
        $limits = [
            MatchKind::Complement->value => (int) Settings::get('retrieval.complements_per_product', $shopId),
            MatchKind::Alternative->value => (int) Settings::get('retrieval.alternatives_per_product', $shopId),
        ];
        $system = self::prompt($limits['complement'], $limits['alternative']);
        $perRun = $only === null ? (int) Settings::get('retrieval.match_products_per_run', $shopId) : count($only);
        $take = (int) Settings::get('retrieval.match_candidates');

        /** @var list<CandidateSource> $sources */
        $sources = iterator_to_array(app()->tagged('retrieval.candidates'), false);

        foreach ($sources as $source) {
            $source->prepare($shopId);
        }

        $products = CatalogProduct::query()->active()->get(['id', 'shop_id', 'external_id', 'title', 'brand', 'price', 'currency', 'in_stock', 'purchasable', 'payload'])->keyBy('id');
        $available = $products->map(fn (CatalogProduct $p): bool => (bool) $p->in_stock && (bool) $p->purchasable)->all();
        $previous = RetrievalMatchRequest::query()->get(['id', 'product_id', 'input_hash', 'status'])->keyBy('product_id');

        $tally = [
            'asked' => 0, 'unchanged' => 0, 'too_few' => 0, 'failed' => 0, 'stopped' => null,
            'accepted' => ['complement' => 0, 'alternative' => 0], 'rejected' => [],
            'offered' => array_fill_keys(array_map(fn (CandidateSource $s): string => $s->key(), $sources), 0),
            'cost_usd' => 0.0, 'model' => $choice->label(), 'samples' => [],
        ];

        if ($choice->provider === null) {
            $tally['stopped'] = 'unknown_provider';
        }

        foreach ($tally['stopped'] === null ? $this->order($products, $shopId, $only) : [] as $product) {
            if ($tally['asked'] >= $perRun) {
                break;
            }

            $candidates = $this->candidates($product, $sources, $take, $available);
            $input = $this->input($product, $candidates, $products);
            $hash = self::fingerprint($product, $candidates, [self::PROMPT_VERSION, $choice->label(), $limits]);
            $last = $previous->get($product->id);

            if ($only === null && $last !== null && $last->input_hash === $hash && $last->status !== MatchRequestStatus::Failed) {
                $tally['unchanged']++;

                continue;
            }

            if (count($candidates) < 2) {
                $this->save($product, MatchRequestStatus::TooFew, $hash, $candidates);
                $tally['too_few']++;

                continue;
            }

            foreach ($candidates as $candidate) {
                foreach ($candidate['sources'] as $key) {
                    $tally['offered'][$key] = ($tally['offered'][$key] ?? 0) + 1;
                }
            }

            if ($this->spend->spentThisMonth() >= $this->spend->cap() * (float) Settings::get('retrieval.match_max_share_of_cap')) {
                $tally['stopped'] = 'share_of_cap';
                break;
            }

            $user = (string) json_encode($input, JSON_UNESCAPED_UNICODE);
            $maxOutput = (int) Settings::get('retrieval.match_max_output_tokens');
            $inPrice = (float) Settings::get('retrieval.match_input_usd_per_million');
            $outPrice = (float) Settings::get('retrieval.match_output_usd_per_million');
            $effort = (string) Settings::get('retrieval.match_reasoning_effort');

            try {
                $this->spend->assertCanSpend((mb_strlen($system) + mb_strlen($user)) / self::CHARS_PER_TOKEN * $inPrice / 1_000_000 + $maxOutput * $outPrice / 1_000_000);
                $reply = $this->models->json($choice->provider, $choice->model, $system, $user, $maxOutput, $effort === 'model_default' ? null : $effort);
            } catch (SpendCapReached) {
                $tally['stopped'] = 'spend_cap';
                break;
            } catch (ModelCallFailed $e) {
                $this->save($product, MatchRequestStatus::Failed, $hash, $candidates, error: $e->reason, choice: $choice);
                $tally['failed']++;

                // Nothing will go better for the next product without a key or a driver.
                if (in_array($e->reason, [ModelCallFailed::NO_KEY, ModelCallFailed::UNSUPPORTED], true)) {
                    $tally['stopped'] = $e->reason;
                    break;
                }

                continue;
            }

            $cost = $reply->costUsd($inPrice, $outPrice);
            $run->usage($choice->providerName, $choice->model, $reply->inputTokens, $reply->outputTokens, 0, $cost);
            $tally['cost_usd'] += $cost;
            $tally['asked']++;

            $verdicts = MatchCheck::judge($reply->data, $candidates, $limits, $available, (float) Settings::get('retrieval.min_alternative_similarity'));
            $this->save($product, MatchRequestStatus::Answered, $hash, $candidates, $reply->data, $verdicts, $choice, $reply->inputTokens, $reply->outputTokens, $cost);

            foreach ($verdicts as $verdict) {
                if ($verdict['status'] === MatchStatus::Accepted) {
                    $tally['accepted'][$verdict['kind']->value]++;
                } else {
                    $tally['rejected'][$verdict['rejected_because']] = ($tally['rejected'][$verdict['rejected_because']] ?? 0) + 1;
                }

                if (count($tally['samples']) < self::SAMPLES && $verdict['product_id'] !== null) {
                    $tally['samples'][] = [
                        'product' => $product->title,
                        'related' => $products->get($verdict['product_id'])?->title,
                        'kind' => $verdict['kind']->value,
                        'status' => $verdict['status']->value,
                        'because' => $verdict['rejected_because'],
                        'why' => $verdict['reason'],
                    ];
                }
            }
        }

        $tally['cost_usd'] = round($tally['cost_usd'], 6);

        $run->output($tally)->summary('retrieval::runs.matched', [
            'asked' => (string) $tally['asked'],
            'accepted' => (string) array_sum($tally['accepted']),
            'rejected' => (string) array_sum($tally['rejected']),
            'unchanged' => (string) $tally['unchanged'],
        ]);
    }

    /**
     * What decides whether a product is asked about again: its text, which products were
     * offered, and their evidence in coarse bands — not the exact numbers. Every new order moves
     * every lift a little, and prices change daily; asking again for that would re-ask the whole
     * shop every night for answers that would not change.
     *
     * @param  array<string, array{product_id: string, sources: list<string>, signals: array<string, mixed>}>  $candidates
     * @param  list<mixed>  $context
     */
    public static function fingerprint(CatalogProduct $product, array $candidates, array $context): string
    {
        $band = fn (float $value, array $steps): float => (float) (collect($steps)->filter(fn (float $step): bool => $value >= $step)->last() ?? 0);

        $offered = array_map(fn (array $c): array => [
            $c['product_id'],
            $band((float) ($c['signals']['orders_together'] ?? 0), [1, 2, 3, 5, 10, 20, 50, 100]),
            $band((float) ($c['signals']['lift'] ?? 0), [1, 2, 5, 10]),
            $band((float) ($c['signals']['guides_together'] ?? 0), [1, 2, 5]),
            round((float) ($c['signals']['similarity'] ?? 0) * 20) / 20,
        ], array_values($candidates));

        usort($offered, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return hash('sha256', (string) json_encode([hash('sha256', ProductDocuments::text($product)), $product->title, $offered, $context], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Best sellers first, then everything else in a stable order.
     *
     * @param  Collection<string, CatalogProduct>  $products
     * @param  list<string>|null  $only
     * @return iterable<CatalogProduct>
     */
    private function order(Collection $products, string $shopId, ?array $only): iterable
    {
        if ($only !== null) {
            return $products->only($only)->values();
        }

        $sales = Purchases::read($shopId);

        return $products->sortBy(fn (CatalogProduct $p): array => [-$sales->ordersOf($p->external_id), $p->id])->values();
    }

    /**
     * What every source found, taken in turns so no one source crowds out the others, merged
     * per product with every source's evidence, and cut to the number the model is shown.
     *
     * @param  list<CandidateSource>  $sources
     * @param  array<string, bool>  $available
     * @return array<string, array{product_id: string, sources: list<string>, signals: array<string, mixed>}> by ref: c1, c2...
     */
    private function candidates(CatalogProduct $product, array $sources, int $take, array $available): array
    {
        $lists = array_map(fn (CandidateSource $s): array => array_values(array_filter(
            $s->candidates($product, $take),
            fn (Candidate $c): bool => $c->productId !== $product->id && ($available[$c->productId] ?? false),
        )), $sources);

        $merged = [];
        $depth = max([0, ...array_map('count', $lists)]);

        for ($i = 0; $i < $depth && count($merged) < $take; $i++) {
            foreach ($lists as $list) {
                $candidate = $list[$i] ?? null;

                if ($candidate === null) {
                    continue;
                }

                if (! isset($merged[$candidate->productId]) && count($merged) >= $take) {
                    continue;
                }

                $merged[$candidate->productId] ??= ['product_id' => $candidate->productId, 'sources' => [], 'signals' => []];
                $merged[$candidate->productId]['sources'][] = $candidate->source;
                $merged[$candidate->productId]['signals'] += $candidate->signals;
            }
        }

        // Every source's evidence for every product it found, even past its turn.
        foreach ($lists as $list) {
            foreach ($list as $candidate) {
                if (isset($merged[$candidate->productId]) && ! in_array($candidate->source, $merged[$candidate->productId]['sources'], true)) {
                    $merged[$candidate->productId]['sources'][] = $candidate->source;
                    $merged[$candidate->productId]['signals'] += $candidate->signals;
                }
            }
        }

        $byRef = [];

        foreach (array_values($merged) as $i => $candidate) {
            $byRef['c'.($i + 1)] = $candidate;
        }

        return $byRef;
    }

    /**
     * @param  array<string, array{product_id: string, sources: list<string>, signals: array<string, mixed>}>  $candidates
     * @param  Collection<string, CatalogProduct>  $products
     * @return array<string, mixed>
     */
    private function input(CatalogProduct $product, array $candidates, Collection $products): array
    {
        $card = fn (CatalogProduct $p): array => array_filter([
            'title' => $p->title,
            'category' => implode(' > ', $p->categoryPaths()[0] ?? []),
            'brand' => (string) $p->brand,
            'price' => $p->price === null ? null : $p->price.' '.$p->currency,
        ], fn ($v): bool => $v !== null && $v !== '');

        $offered = [];

        foreach ($candidates as $ref => $candidate) {
            $related = $products->get($candidate['product_id']);

            if ($related !== null) {
                $offered[] = ['ref' => $ref] + $card($related) + ['signals' => $candidate['signals']];
            }
        }

        return [
            'product' => $card($product) + ['text' => mb_substr(ProductDocuments::text($product), 0, self::TEXT_CHARS)],
            'candidates' => $offered,
        ];
    }

    /**
     * @param  array<string, array{product_id: string, sources: list<string>, signals: array<string, mixed>}>  $candidates
     * @param  array<string, mixed>|null  $answer
     * @param  list<array<string, mixed>>  $verdicts
     */
    private function save(
        CatalogProduct $product,
        MatchRequestStatus $status,
        string $hash,
        array $candidates,
        ?array $answer = null,
        array $verdicts = [],
        ?ModelChoice $choice = null,
        int $inputTokens = 0,
        int $outputTokens = 0,
        float $cost = 0.0,
        ?string $error = null,
    ): void {
        DB::transaction(function () use ($product, $status, $hash, $candidates, $answer, $verdicts, $choice, $inputTokens, $outputTokens, $cost, $error): void {
            $request = RetrievalMatchRequest::query()->updateOrCreate(['shop_id' => $product->shop_id, 'product_id' => $product->id], [
                'status' => $status,
                'input_hash' => $hash,
                'candidates' => array_map(
                    fn (string $ref, array $c): array => ['ref' => $ref] + $c,
                    array_keys($candidates),
                    array_values($candidates),
                ),
                'answer' => $answer,
                'error' => $error,
                'provider' => $choice?->providerName,
                'model' => $choice?->model,
                'prompt_version' => $choice === null ? null : (string) self::PROMPT_VERSION,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => $cost,
                'asked_at' => $choice === null ? null : now(),
            ]);

            // A failed request keeps the last good picks: a bad night is not a reason to forget.
            if ($status === MatchRequestStatus::Failed) {
                return;
            }

            RetrievalMatch::query()->where('request_id', $request->id)->delete();

            foreach ($verdicts as $verdict) {
                RetrievalMatch::query()->create([
                    'shop_id' => $product->shop_id,
                    'request_id' => $request->id,
                    'product_id' => $product->id,
                    'related_product_id' => $verdict['product_id'],
                    'kind' => $verdict['kind'],
                    'status' => $verdict['status'],
                    'rejected_because' => $verdict['rejected_because'],
                    'position' => $verdict['position'],
                    'reason' => $verdict['reason'],
                    'signals' => $verdict['signals'],
                ]);
            }
        });
    }
}
