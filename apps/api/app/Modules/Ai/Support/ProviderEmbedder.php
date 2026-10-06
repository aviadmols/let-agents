<?php

namespace App\Modules\Ai\Support;

use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\EmbeddingDriver;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ImageEmbeddingDriver;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;

/** Hands texts and pictures to the driver of the provider asked for, with that provider's key. */
final class ProviderEmbedder implements Embedder, ImageEmbedder
{
    /**
     * @param  iterable<EmbeddingDriver>  $drivers
     * @param  iterable<ImageEmbeddingDriver>  $imageDrivers
     */
    public function __construct(
        private readonly iterable $drivers,
        private readonly iterable $imageDrivers = [],
    ) {}

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

    public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
    {
        if ($images === []) {
            return new Embeddings([], 0);
        }

        return $this->imageDriver($provider)->embedImages(ProviderKey::for($provider), $model, array_values($images), $dimensions);
    }

    public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
    {
        if ($texts === []) {
            return new Embeddings([], 0);
        }

        return $this->imageDriver($provider)->embedTextsForImages(ProviderKey::for($provider), $model, array_values($texts), $dimensions, $query);
    }

    private function imageDriver(AiProviderName $provider): ImageEmbeddingDriver
    {
        foreach ($this->imageDrivers as $driver) {
            if ($driver->provider() === $provider) {
                return $driver;
            }
        }

        throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
    }
}
