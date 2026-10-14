<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * One picture made by a model with the key saved in the panel. Callers ask SpendGuard first and
 * record the cost on their run: providers charge per picture, so the price is a setting of the caller.
 */
interface ImageModel
{
    /**
     * @param  list<array{mime: string, bytes: string}>  $images
     *
     * @throws ModelCallFailed
     */
    public function generate(AiProviderName $provider, string $model, string $prompt, array $images): ImageReply;
}
