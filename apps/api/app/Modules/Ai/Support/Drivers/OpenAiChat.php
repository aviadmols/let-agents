<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\ChatDriver;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionDriver;
use App\Modules\Ai\Enums\AiProviderName;
use GuzzleHttp\Client;
use OpenAI;
use Throwable;

/** Chat completions through openai-php/client, asking for a JSON object. */
final class OpenAiChat implements ChatDriver, VisionDriver
{
    private const TIMEOUT_SECONDS = 45.0;

    public function provider(): AiProviderName
    {
        return AiProviderName::OpenAi;
    }

    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, $reasoningEffort);
    }

    public function jsonWithImage(string $apiKey, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, [
            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes)]],
            ['type' => 'text', 'text' => $user],
        ], $maxOutputTokens, $reasoningEffort);
    }

    /** @param string|list<array<string, mixed>> $content */
    private function send(string $apiKey, string $model, string $system, string|array $content, int $maxOutputTokens, ?string $reasoningEffort): ModelReply
    {
        try {
            $response = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withHttpClient(new Client(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 10]))
                ->make()
                ->chat()
                ->create(array_filter([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $content],
                    ],
                    'response_format' => ['type' => 'json_object'],
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
