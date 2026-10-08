<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * One request to a model that looks at a picture, with the key saved in the panel, answered as a
 * JSON object. Callers ask SpendGuard first and record the reply's tokens and cost on their run.
 */
interface VisionModel
{
    /**
     * @param  string  $bytes  the picture itself (JPEG, PNG, WebP or GIF)
     *
     * @throws ModelCallFailed when there is no connected key, the provider refuses, or the reply is not JSON
     */
    public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply;
}
