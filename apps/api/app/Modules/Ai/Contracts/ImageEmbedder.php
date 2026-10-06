<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * Turns pictures into vectors, in the same space as words, with the key saved in the panel.
 *
 * A text embedded here is comparable with the pictures ("striped shirt" lands near striped
 * shirts), and never with vectors from Embedder: different models, different spaces. Callers
 * ask SpendGuard first, record the cost, and keep the model name beside every vector.
 */
interface ImageEmbedder
{
    /**
     * @param  list<array{mime: string, data: string}>  $images  raw bytes, in the order the vectors come back
     *
     * @throws ModelCallFailed when there is no connected key, no driver for pictures, or the provider refuses
     */
    public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings;

    /**
     * Words to compare with pictures. $query says whether these are a shopper's words (a search)
     * or a description to classify against, which some models embed differently.
     *
     * @param  list<string>  $texts
     *
     * @throws ModelCallFailed
     */
    public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings;
}
