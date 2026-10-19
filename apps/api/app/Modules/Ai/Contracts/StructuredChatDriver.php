<?php

namespace App\Modules\Ai\Contracts;

/** A ChatDriver whose provider can hold its reply to a JSON Schema (StructuredChatModel). */
interface StructuredChatDriver extends ChatDriver
{
    /**
     * @param  array<string, mixed>  $schema
     *
     * @throws ModelCallFailed when the provider refuses or the reply is not JSON
     */
    public function structured(string $apiKey, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply;
}
