<?php

namespace ImageCaptionBenchmark;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionDriver;
use App\Modules\Ai\Enums\AiProviderName;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gemini reading a picture, for the benchmark only: the production VisionDriver contract, but not
 * registered in AiServiceProvider, so nothing outside this folder can reach it.
 *
 * Plain REST like GeminiEmbeddings. Thinking tokens are billed as output, so they are counted as output.
 */
final class GeminiVision implements VisionDriver
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const TIMEOUT_SECONDS = 60;

    public function provider(): AiProviderName
    {
        return AiProviderName::Gemini;
    }

    public function jsonWithImage(string $apiKey, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        $generation = ['maxOutputTokens' => $maxOutputTokens, 'responseMimeType' => 'application/json'];

        if ($reasoningEffort !== null) {
            $generation['thinkingConfig'] = ['thinkingLevel' => $reasoningEffort];
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->post(self::URL.rawurlencode($model).':generateContent', [
                    'system_instruction' => ['parts' => [['text' => $system]]],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                            ['text' => $user],
                        ],
                    ]],
                    'generationConfig' => $generation,
                ]);
        } catch (ConnectionException $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        if (! $response->successful()) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, new \RuntimeException('Gemini '.$response->status().': '.mb_substr((string) $response->body(), 0, 500)));
        }

        $text = '';

        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            if (! ($part['thought'] ?? false) && isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        $usage = (array) $response->json('usageMetadata', []);

        return new ModelReply($data, (int) ($usage['promptTokenCount'] ?? 0), (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0));
    }
}
