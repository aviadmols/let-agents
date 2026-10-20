<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\StructuredChatDriver;
use App\Modules\Ai\Contracts\VisionDriver;
use App\Modules\Ai\Enums\AiProviderName;
use GuzzleHttp\Client;
use OpenAI;
use Throwable;

/** Chat completions through openai-php/client, asking for a JSON object. */
final class OpenAiChat implements StructuredChatDriver, VisionDriver
{
    private const TIMEOUT_SECONDS = 45.0;

    /** A reply allowed many tokens (a store's tag bank reviewed, products tagged) is given longer: this many tokens a second, up to MAX_TIMEOUT_SECONDS. */
    private const TOKENS_PER_SECOND = 40;

    private const MAX_TIMEOUT_SECONDS = 300.0;

    public function provider(): AiProviderName
    {
        return AiProviderName::OpenAi;
    }

    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, $reasoningEffort);
    }

    public function structured(string $apiKey, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, $reasoningEffort, ['type' => 'json_schema', 'json_schema' => ['name' => 'reply', 'strict' => true, 'schema' => $schema]]);
    }

    public function jsonWithImage(string $apiKey, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, [
            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes)]],
            ['type' => 'text', 'text' => $user],
        ], $maxOutputTokens, $reasoningEffort);
    }

    /**
     * @param  string|list<array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $format  a strict json_schema; a JSON object otherwise
     */
    private function send(string $apiKey, string $model, string $system, string|array $content, int $maxOutputTokens, ?string $reasoningEffort, ?array $format = null): ModelReply
    {
        try {
            $response = OpenAI::factory()
                ->withApiKey($apiKey)
                // a short reply (a shopper's answer, up to 1,800 tokens) waits as it did; a long one, background work, longer
                ->withHttpClient(new Client(['timeout' => min(self::MAX_TIMEOUT_SECONDS, max(self::TIMEOUT_SECONDS, $maxOutputTokens / self::TOKENS_PER_SECOND)), 'connect_timeout' => 10]))
                ->make()
                ->chat()
                ->create(array_filter([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $content],
                    ],
                    'response_format' => $format ?? ['type' => 'json_object'],
                    'max_completion_tokens' => $maxOutputTokens,
                    'reasoning_effort' => $reasoningEffort,
                ], fn ($value): bool => $value !== null && $value !== ''));
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $data = json_decode((string) ($response->choices[0]->message->content ?? ''), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        return new ModelReply($data, (int) ($response->usage?->promptTokens ?? 0), (int) ($response->usage?->completionTokens ?? 0));
    }
}
