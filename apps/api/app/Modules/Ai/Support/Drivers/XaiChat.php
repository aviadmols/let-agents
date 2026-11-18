<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\StructuredChatDriver;
use App\Modules\Ai\Enums\AiProviderName;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * xAI's Grok as a writer of JSON answers, to measure against the writer in use (Aviad, 18.11).
 *
 * Plain REST through Laravel's client, as GeminiChat: xAI has no PHP SDK. Its chat completions take OpenAI's shape (the
 * system and the user's turn; a schema, when given, as a strict json_schema). The effort asked for is xAI's reasoning
 * effort (low, medium, high), sent only where it is asked: a model that takes none is asked once more without it, and a
 * model that names the limit of its reply max_tokens is asked once more so. Reasoning tokens are paid as output: where
 * the usage counts them apart from the reply's (its total over the prompt and the reply), they are added to it. The prompt
 * xAI read from its own cache is counted apart, at its own price.
 */
final class XaiChat implements StructuredChatDriver
{
    public const URL = 'https://api.x.ai/v1/chat/completions';

    private const TIMEOUT_SECONDS = 90;

    public function provider(): AiProviderName
    {
        return AiProviderName::Xai;
    }

    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, null, $reasoningEffort);
    }

    public function structured(string $apiKey, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, $schema, $reasoningEffort);
    }

    /** xAI's reasoning effort for the effort asked: low, medium or high; none when nothing is asked. */
    public static function effort(?string $effort): ?string
    {
        return match ($effort) {
            'minimal', 'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            default => null,
        };
    }

    /** @param  array<string, mixed>|null  $schema */
    private function send(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?array $schema, ?string $effort): ModelReply
    {
        $body = array_filter([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => $schema === null ? ['type' => 'json_object'] : ['type' => 'json_schema', 'json_schema' => ['name' => 'reply', 'strict' => true, 'schema' => $schema]],
            'max_completion_tokens' => $maxOutputTokens,
            'reasoning_effort' => self::effort($effort),
        ], fn ($value): bool => $value !== null);

        $response = $this->post($apiKey, $body);
        // a parameter this model does not take is left out, once each: the effort, then the name of the reply's limit
        if ($response->status() === 400 && isset($body['reasoning_effort']) && stripos($response->body(), 'reasoning') !== false) {
            unset($body['reasoning_effort']);
            $response = $this->post($apiKey, $body);
        }
        if ($response->status() === 400 && stripos($response->body(), 'max_completion_tokens') !== false) {
            $body['max_tokens'] = $body['max_completion_tokens'];
            unset($body['max_completion_tokens']);
            $response = $this->post($apiKey, $body);
        }

        if (! $response->successful()) {
            // 429 is the rate or the credit, worth asking again later; anything else is xAI's own reason, for the log
            throw new ModelCallFailed($response->status() === 429 ? ModelCallFailed::RATE_LIMITED : ModelCallFailed::PROVIDER_ERROR,
                new RuntimeException('xAI '.$response->status().': '.mb_substr($response->body(), 0, 500)));
        }

        $text = (string) $response->json('choices.0.message.content', '');
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        return self::reply($data, (array) $response->json('usage', []));
    }

    /**
     * What the call used: the prompt less what xAI read from its cache; the reply with its reasoning, where the total says
     * the reasoning was counted apart.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $usage
     */
    public static function reply(array $data, array $usage): ModelReply
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        $apart = max(0, (int) ($usage['total_tokens'] ?? 0) - $prompt - $completion);

        return new ModelReply($data, max(0, $prompt - $cached), $completion + $apart, $cached);
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $apiKey, array $body): Response
    {
        try {
            return Http::timeout(self::TIMEOUT_SECONDS)->connectTimeout(10)->withToken($apiKey)->acceptJson()->post(self::URL, $body);
        } catch (ConnectionException $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }
    }
}
