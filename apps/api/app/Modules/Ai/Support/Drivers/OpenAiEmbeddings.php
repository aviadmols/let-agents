<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\EmbeddingDriver;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use GuzzleHttp\Client;
use OpenAI;
use Throwable;

/** OpenAI embeddings (text-embedding-3-small and the like) through openai-php/client. */
final class OpenAiEmbeddings implements EmbeddingDriver
{
    private const TIMEOUT_SECONDS = 60.0;

    public function provider(): AiProviderName
    {
        return AiProviderName::OpenAi;
    }

    public function embed(string $apiKey, string $model, array $texts, ?int $dimensions = null): Embeddings
    {
        try {
            $response = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withHttpClient(new Client(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 10]))
                ->make()
                ->embeddings()
                ->create(array_filter([
                    'model' => $model,
                    'input' => array_values($texts),
                    'dimensions' => $dimensions,
                ], fn ($value): bool => $value !== null));
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $vectors = [];

        foreach ($response->embeddings as $i => $embedding) {
            $vectors[$embedding->index ?? $i] = array_map('floatval', $embedding->embedding);
        }

        ksort($vectors);

        if (count($vectors) !== count($texts)) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
        }

        return new Embeddings(array_values($vectors), (int) ($response->usage?->promptTokens ?? 0));
    }
}
