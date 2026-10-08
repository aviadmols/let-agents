<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\ImageEmbedder;
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
        private readonly ImageEmbedder $imageEmbedder,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * The words' vector in the index by meaning ('text') or in the picture space ('image'),
     * which are different models and never compared with each other.
     *
     * @return list<float>|null
     */
    public function for(string $shopId, string $text, string $space = 'text'): ?array
    {
        $text = mb_substr(trim($text), 0, self::MAX_CHARS);
        $images = $space === 'image';
        $choice = ModelChoice::for($images ? 'image' : 'embedding');

        if ($text === '' || $choice->provider === null || $choice->model === '') {
            return null;
        }

        // Pictures are searched with words embedded as a query, so the same model can hold both
        // kinds of row without one being read as the other.
        $stored = $images ? 'image:'.$choice->model : $choice->model;
        $hash = hash('sha256', $text);
        $saved = RetrievalQueryVector::query()
            ->where('embedding_model', $stored)->where('text_hash', $hash)
            ->first();

        if ($saved !== null) {
            RetrievalQueryVector::query()->whereKey($saved->id)->update(['uses' => DB::raw('uses + 1'), 'last_used_at' => now()]);

            return $saved->vector();
        }

        $price = (float) Settings::get($images ? 'retrieval.image_text_usd_per_million' : 'retrieval.embedding_usd_per_million');
        $dimensions = (int) Settings::get($images ? 'retrieval.image_dimensions' : 'retrieval.embedding_dimensions') ?: null;

        try {
            $this->spend->assertCanSpend(mb_strlen($text) * $price / 1_000_000);
            $embeddings = $images
                ? $this->imageEmbedder->embedTextsForImages($choice->provider, $choice->model, [$text], $dimensions)
                : $this->embedder->embed($choice->provider, $choice->model, [$text], $dimensions);
        } catch (SpendCapReached|ModelCallFailed) {
            return null;
        }

        $vector = $embeddings->vectors[0] ?? null;

        if ($vector === null || $vector === []) {
            return null;
        }

        RetrievalQueryVector::query()->create([
            'shop_id' => $shopId,
            'embedding_model' => $stored,
            'text_hash' => $hash,
            'text' => $text,
            'dimensions' => count($vector),
            'embedding' => VectorSearch::column($vector),
            'last_used_at' => now(),
        ]);

        $this->record($shopId, $choice->providerName, $choice->model, $embeddings->inputTokens, $embeddings->costUsd($price));

        return $vector;
    }

    /**
     * The vector of a photo a shopper uploaded to search by. Priced per picture. The photo itself
     * is never kept: only a hash of its bytes, so the same photo sent again is free.
     *
     * @return list<float>|null
     */
    public function forPhoto(string $shopId, string $mime, string $bytes): ?array
    {
        $choice = ModelChoice::for('image');

        if ($bytes === '' || $choice->provider === null || $choice->model === '') {
            return null;
        }

        $stored = 'photo:'.$choice->model;
        $hash = hash('sha256', $bytes);
        $saved = RetrievalQueryVector::query()->where('embedding_model', $stored)->where('text_hash', $hash)->first();

        if ($saved !== null) {
            RetrievalQueryVector::query()->whereKey($saved->id)->update(['uses' => DB::raw('uses + 1'), 'last_used_at' => now()]);

            return $saved->vector();
        }

        $price = (float) Settings::get('retrieval.image_usd_per_image');

        try {
            $this->spend->assertCanSpend($price);
            $embeddings = $this->imageEmbedder->embedImages($choice->provider, $choice->model, [['mime' => $mime, 'data' => $bytes]], (int) Settings::get('retrieval.image_dimensions') ?: null);
        } catch (SpendCapReached|ModelCallFailed) {
            return null;
        }

        $vector = $embeddings->vectors[0] ?? null;

        if ($vector === null || $vector === []) {
            return null;
        }

        RetrievalQueryVector::query()->create([
            'shop_id' => $shopId,
            'embedding_model' => $stored,
            'text_hash' => $hash,
            'text' => '',
            'dimensions' => count($vector),
            'embedding' => VectorSearch::column($vector),
            'last_used_at' => now(),
        ]);

        $this->record($shopId, $choice->providerName, $choice->model, $embeddings->inputTokens, $price);

        return $vector;
    }

    /** Today's one run for this shop's query vectors, grown by every new query. */
    private function record(string $shopId, string $provider, string $model, int $tokens, float $cost): void
    {
        // The ledger gets every call under its own model; the daily run below is the activity log.
        Run::recordUsage($shopId, self::AGENT, self::ACTION, $provider, $model, $tokens, 0, $cost);

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
