<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/** One provider's way of answering a request about a picture with a JSON object. */
interface VisionDriver
{
    public function provider(): AiProviderName;

    /** @throws ModelCallFailed when the provider refuses or the reply is not JSON */
    public function jsonWithImage(string $apiKey, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply;
}
