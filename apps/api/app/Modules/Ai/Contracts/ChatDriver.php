<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * One provider's way of answering a chat request with a JSON object. ChatModel picks the driver
 * for the provider a caller asks for and hands it the key saved in the panel.
 *
 * A new provider is a class implementing this, a case in AiProviderName, and one line in
 * AiServiceProvider::CHAT_DRIVERS. Nothing that calls ChatModel changes.
 */
interface ChatDriver
{
    public function provider(): AiProviderName;

    /** @throws ModelCallFailed when the provider refuses or the reply is not JSON */
    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply;
}
