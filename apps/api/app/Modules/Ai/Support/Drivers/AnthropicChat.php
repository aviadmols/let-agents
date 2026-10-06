<?php

namespace App\Modules\Ai\Support\Drivers;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Modules\Ai\Contracts\ChatDriver;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use Throwable;

/**
 * Claude through the official Anthropic SDK, one message at a time. Bulk analysis still goes
 * through the Batch API in Enrichment; this is for work an operator chose to run directly.
 *
 * Claude has no JSON mode without a schema, so the system prompt asks for one JSON object and
 * the reply is read from its first brace to its last. Reasoning effort is not passed on.
 */
final class AnthropicChat implements ChatDriver
{
    private const TIMEOUT_SECONDS = 60.0;

    private const JSON_ONLY = "\n\nAnswer with one JSON object and nothing else: no prose, no code fence.";

    public function provider(): AiProviderName
    {
        return AiProviderName::Anthropic;
    }

    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        try {
            $message = (new Client(apiKey: $apiKey, requestOptions: RequestOptions::with(timeout: self::TIMEOUT_SECONDS, maxRetries: 1)))
                ->messages
                ->create(
                    maxTokens: $maxOutputTokens,
                    messages: [['role' => 'user', 'content' => $user]],
                    model: $model,
                    system: $system.self::JSON_ONLY,
                );
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $text = '';

        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        return new ModelReply($data, $message->usage->inputTokens, $message->usage->outputTokens);
    }
}
