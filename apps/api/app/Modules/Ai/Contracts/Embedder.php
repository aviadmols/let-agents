<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * Turns texts into vectors with the key saved in the panel, whichever provider and model the
 * caller's settings name.
 *
 * Callers ask SpendGuard first and record the tokens and cost on their run. Vectors from
 * different models are never comparable: a caller stores the model name with each vector and
 * builds again when the setting changes.
 */
interface Embedder
{
    /**
     * @param  list<string>  $texts  in the order the vectors come back
     *
     * @throws ModelCallFailed when there is no connected key, no driver, or the provider refuses
     */
    public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings;
}
