<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\ChatDriver;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use GuzzleHttp\Client;
use OpenAI;
use Throwable;

/** Chat completions through openai-php/client, asking for a JSON object. */
final class OpenAiChat implements ChatDriver
{
    private const TIMEOUT_SECONDS = 45.0;

    public function provider(): AiProviderName
    {
        return AiProviderName::OpenAi;
    }

    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
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
                        ['role' => 'user', 'content' => $user],
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
