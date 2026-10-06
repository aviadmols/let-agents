<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\EmbeddingDriver;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;

/** Hands texts to the embedding driver of the provider asked for, with that provider's key. */
final class ProviderEmbedder implements Embedder
{
    /** @param iterable<EmbeddingDriver> $drivers */
    public function __construct(private readonly iterable $drivers) {}

    public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
    {
        if ($texts === []) {
            return new Embeddings([], 0);
        }

        foreach ($this->drivers as $driver) {
            if ($driver->provider() === $provider) {
                return $driver->embed(ProviderKey::for($provider), $model, array_values($texts), $dimensions);
            }
        }

        throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }
}
