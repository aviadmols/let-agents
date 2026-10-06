<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * One provider's way of turning texts into vectors. Embedder picks the driver for the provider a
 * caller asks for and hands it the key saved in the panel.
 *
 * A new provider is a class implementing this, a case in AiProviderName, and one line in
 * AiServiceProvider::EMBEDDING_DRIVERS.
 */
interface EmbeddingDriver
{
    public function provider(): AiProviderName;

    /**
     * @param  list<string>  $texts
     *
     * @throws ModelCallFailed
     */
    public function embed(string $apiKey, string $model, array $texts, ?int $dimensions = null): Embeddings;
}
