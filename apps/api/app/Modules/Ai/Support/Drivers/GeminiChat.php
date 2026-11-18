<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\StructuredChatDriver;
use App\Modules\Ai\Enums\AiProviderName;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google's Gemini as a writer of JSON answers (gemini-3.8-flash and its kin), as Aviad asked, 18.11.
 *
 * Plain REST through Laravel's client, as GeminiEmbeddings: Google has no PHP SDK for the Gemini API, and the key goes in a
 * header, never in the address. The instructions go as systemInstruction, the input as the user's turn; a schema, when
 * given, holds the reply to it (responseJsonSchema). The effort asked for is Gemini's thinking level: "minimal" is not
 * taken by every model and is read as "low". Thinking tokens are paid as output; the prompt Google read from its own cache
 * (implicit caching) is counted apart, at its own price.
 */
final class GeminiChat implements StructuredChatDriver
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const TIMEOUT_SECONDS = 90;

    public function provider(): AiProviderName
    {
        return AiProviderName::Gemini;
    }

    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, null, $reasoningEffort);
    }

    public function structured(string $apiKey, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, $schema, $reasoningEffort);
    }

    /** Gemini's thinking level for the effort asked: low, medium or high; none when nothing is asked. */
    public static function thinkingLevel(?string $effort): ?string
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
        $level = self::thinkingLevel($effort);
        $config = array_filter([
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => $schema,
            'maxOutputTokens' => $maxOutputTokens,
            'thinkingConfig' => $level === null ? null : ['thinkingLevel' => $level],
        ], fn ($value): bool => $value !== null);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->post(self::URL.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => $config,
                ]);
        } catch (ConnectionException $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        if (! $response->successful()) {
            // 429 is the quota, worth asking again later; anything else is Gemini's own reason, for the log
            throw new ModelCallFailed($response->status() === 429 ? ModelCallFailed::RATE_LIMITED : ModelCallFailed::PROVIDER_ERROR,
                new \RuntimeException('Gemini '.$response->status().': '.mb_substr((string) $response->body(), 0, 500)));
        }

        // the reply's text, its thoughts left out
        $text = '';
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true && isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        $usage = (array) $response->json('usageMetadata', []);
        $prompt = (int) ($usage['promptTokenCount'] ?? 0);
        $cached = (int) ($usage['cachedContentTokenCount'] ?? 0);

        return new ModelReply($data, max(0, $prompt - $cached), (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0), $cached);
    }
}
