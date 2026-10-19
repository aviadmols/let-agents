<?php

namespace App\Modules\Ai\Contracts;

use App\Modules\Ai\Enums\AiProviderName;

/**
 * A chat request whose reply the provider holds to a JSON Schema (structured output: Anthropic's
 * output_config.format, OpenAI's strict json_schema), so it always parses. Asked for by a prompt that
 * has a schema file beside it; a provider whose driver cannot is asked as ChatModel::json asks.
 */
interface StructuredChatModel
{
    /**
     * @param  array<string, mixed>  $schema  every object closed (additionalProperties false), every property required
     *
     * @throws ModelCallFailed when the provider refuses or the reply is not JSON
     */
    public function structured(AiProviderName $provider, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply;
}
