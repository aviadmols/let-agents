<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\QueryEmbeddingDriver;
use App\Modules\Ai\Enums\AiProviderName;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Voyage AI embeddings (voyage-4-large, voyage-4, voyage-4-lite): words only, a query embedded apart
 * from the documents it searches (input_type query or document), as Voyage asks for retrieval.
 *
 * Plain REST through Laravel's client: Voyage has no PHP SDK. Up to 1,000 inputs a request; the
 * callers send far fewer. The token count is Voyage's own.
 */
final class VoyageEmbeddings implements QueryEmbeddingDriver
{
    private const URL = 'https://api.voyageai.com/v1/embeddings';

    private const TIMEOUT_SECONDS = 60;

    public function provider(): AiProviderName
    {
        return AiProviderName::Voyage;
    }

    public function embed(string $apiKey, string $model, array $texts, ?int $dimensions = null): Embeddings
    {
        return $this->request($apiKey, $model, $texts, $dimensions, 'document');
    }

    public function embedQueries(string $apiKey, string $model, array $texts, ?int $dimensions = null): Embeddings
    {
        return $this->request($apiKey, $model, $texts, $dimensions, 'query');
    }

    /** @param list<string> $texts */
    private function request(string $apiKey, string $model, array $texts, ?int $dimensions, string $inputType): Embeddings
    {
        $texts = array_values($texts);

        if ($texts === []) {
            return new Embeddings([], 0);
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withToken($apiKey)
                ->acceptJson()
                ->post(self::URL, array_filter([
                    'input' => $texts,
                    'model' => $model,
                    'input_type' => $inputType,
                    'output_dimension' => $dimensions,
                ], fn ($value): bool => $value !== null));
        } catch (ConnectionException $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        if (! $response->successful()) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, new \RuntimeException('Voyage '.$response->status().': '.mb_substr((string) $response->body(), 0, 500)));
        }

        // in the order asked, whatever order they come back in
        $vectors = [];

        foreach ((array) $response->json('data', []) as $row) {
            $values = $row['embedding'] ?? null;

            if (! is_array($values) || $values === []) {
                throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
            }

            $vectors[(int) ($row['index'] ?? count($vectors))] = array_map('floatval', $values);
        }

        ksort($vectors);

        if (count($vectors) !== count($texts)) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
        }

        return new Embeddings(array_values($vectors), (int) $response->json('usage.total_tokens', 0));
    }
}
