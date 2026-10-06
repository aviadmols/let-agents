<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Retrieval\Models\RetrievalQueryVector;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Runs\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * The vector of a shopper's query, made once per shop, model and wording.
 *
 * A new wording costs one embedding call (a fraction of a cent per thousand), asks SpendGuard
 * first, and adds its tokens and cost to one run per shop per day, so the cap counts it and the
 * activity screen shows one line a day instead of one per search. Without a key, a model, or
 * budget, there is simply no vector and the search goes on by spelling alone.
 */
final class QueryVectors
{
    public const AGENT = 'retrieval.query_embedder';

    public const ACTION = 'retrieval.embed_queries';

    private const MAX_CHARS = 300;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly SpendGuard $spend,
    ) {}

    /** @return list<float>|null */
    public function for(string $shopId, string $text): ?array
    {
        $text = mb_substr(trim($text), 0, self::MAX_CHARS);
        $choice = ModelChoice::for('embedding');

        if ($text === '' || $choice->provider === null || $choice->model === '') {
            return null;
        }

        $hash = hash('sha256', $text);
        $saved = RetrievalQueryVector::query()
            ->where('embedding_model', $choice->model)->where('text_hash', $hash)
            ->first();

        if ($saved !== null) {
            RetrievalQueryVector::query()->whereKey($saved->id)->update(['uses' => DB::raw('uses + 1'), 'last_used_at' => now()]);

            return $saved->vector();
        }

        $price = (float) Settings::get('retrieval.embedding_usd_per_million');
        $dimensions = (int) Settings::get('retrieval.embedding_dimensions') ?: null;

        try {
            $this->spend->assertCanSpend(mb_strlen($text) * $price / 1_000_000);
            $embeddings = $this->embedder->embed($choice->provider, $choice->model, [$text], $dimensions);
        } catch (SpendCapReached|ModelCallFailed) {
            return null;
        }

        $vector = $embeddings->vectors[0] ?? null;

        if ($vector === null || $vector === []) {
            return null;
        }

        RetrievalQueryVector::query()->create([
            'shop_id' => $shopId,
            'embedding_model' => $choice->model,
            'text_hash' => $hash,
            'text' => $text,
            'dimensions' => count($vector),
            'embedding' => VectorSearch::column($vector),
            'last_used_at' => now(),
        ]);

        $this->record($shopId, $choice->providerName, $choice->model, $embeddings->inputTokens, $embeddings->costUsd($price));

        return $vector;
    }

    /** Today's one run for this shop's query vectors, grown by every new query. */
    private function record(string $shopId, string $provider, string $model, int $tokens, float $cost): void
    {
        $run = Run::query()
            ->where('shop_id', $shopId)->where('agent', self::AGENT)
            ->where('started_at', '>=', now()->startOfDay())
            ->latest('started_at')
            ->first();

        if ($run === null) {
            Run::query()->create([
                'shop_id' => $shopId,
                'agent' => self::AGENT,
                'action' => self::ACTION,
                'status' => RunStatus::Succeeded,
                'trigger' => RunTrigger::Webhook,
                'summary_key' => 'retrieval::runs.query_vectors',
                'summary_params' => ['count' => '1'],
                'provider' => $provider,
                'model' => $model,
                'input_tokens' => $tokens,
                'cost_usd' => round($cost, 6),
                // A query costs millionths of a dollar, below the column's precision: the exact
                // running total lives here and the column is rounded from it.
                'output' => ['queries' => 1, 'cost_usd' => $cost],
                'started_at' => now(),
                'finished_at' => now(),
                'duration_ms' => 0,
            ]);

            return;
        }

        $count = (int) ($run->summary_params['count'] ?? 0) + 1;
        $total = (float) ($run->output['cost_usd'] ?? $run->cost_usd) + $cost;

        $run->forceFill([
            'input_tokens' => $run->input_tokens + max(0, $tokens),
            'cost_usd' => round($total, 6),
            'output' => ['queries' => $count, 'cost_usd' => $total],
            'summary_params' => ['count' => (string) $count],
            'finished_at' => now(),
        ])->save();
    }
}
