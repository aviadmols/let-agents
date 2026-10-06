<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * One provider's way of putting pictures and words into one space. A provider that can is a
 * class implementing this, listed in AiServiceProvider::IMAGE_EMBEDDING_DRIVERS.
 */
interface ImageEmbeddingDriver
{
    public function provider(): AiProviderName;

    /**
     * @param  list<array{mime: string, data: string}>  $images
     *
     * @throws ModelCallFailed
     */
    public function embedImages(string $apiKey, string $model, array $images, ?int $dimensions = null): Embeddings;

    /**
     * @param  list<string>  $texts
     *
     * @throws ModelCallFailed
     */
    public function embedTextsForImages(string $apiKey, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings;
}
