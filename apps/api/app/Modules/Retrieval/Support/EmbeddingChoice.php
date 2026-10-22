<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Enums\AiProviderName;

/**
 * The words' model of one shop, with what a call to it needs: the vector size it is asked for
 * (null: the model's own) and its price. Read from `retrieval.embedding_*` for the shop, or named
 * outright for a model not chosen yet (retrieval:switch-embedder tries it before choosing it).
 */
final class EmbeddingChoice
{
    public function __construct(
        public readonly ModelChoice $choice,
        public readonly ?int $dimensions,
        public readonly float $usdPerMillion,
    ) {}

    public static function forShop(?string $shopId = null): self
    {
        $shopId ??= app(TenantContext::class)->id();

        return new self(
            ModelChoice::for('embedding', $shopId),
            (int) Settings::get('retrieval.embedding_dimensions', $shopId) ?: null,
            (float) Settings::get('retrieval.embedding_usd_per_million', $shopId),
        );
    }

    public static function of(string $provider, string $model, ?int $dimensions, float $usdPerMillion): self
    {
        $provider = strtolower(trim($provider));

        return new self(new ModelChoice(AiProviderName::tryFrom($provider), $provider, trim($model)), $dimensions ?: null, $usdPerMillion);
    }

    public function model(): string
    {
        return $this->choice->model;
    }
}
