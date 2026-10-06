<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\EmbeddingDriver;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbeddingDriver;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google's Gemini embeddings (gemini-embedding-2): words and pictures in one space, so "striped
 * shirt" lands near pictures of striped shirts.
 *
 * Plain REST through Laravel's client: Google has no PHP SDK for the Gemini API. The key goes in
 * a header, never in the address. One batchEmbedContents request carries every input.
 *
 * Gemini returns no token count for embeddings, so the count here is an estimate the caller
 * prices from its own settings: about 258 tokens a picture, about four characters a token.
 */
final class GeminiEmbeddings implements EmbeddingDriver, ImageEmbeddingDriver
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const TIMEOUT_SECONDS = 60;

    public const TOKENS_PER_IMAGE = 258;

    /** gemini-embedding-2 takes the task as words in front of the text, not as a parameter. */
    private const QUERY_PREFIX = 'task: search result | query: ';

    public function provider(): AiProviderName
    {
        return AiProviderName::Gemini;
    }

    public function embed(string $apiKey, string $model, array $texts, ?int $dimensions = null): Embeddings
    {
        return $this->batch($apiKey, $model, array_map(fn (string $text): array => ['text' => $text], array_values($texts)), $dimensions, $this->textTokens($texts));
    }

    public function embedTextsForImages(string $apiKey, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
    {
        $texts = array_values(array_map(fn (string $text): string => $query ? self::QUERY_PREFIX.$text : $text, $texts));

        return $this->batch($apiKey, $model, array_map(fn (string $text): array => ['text' => $text], $texts), $dimensions, $this->textTokens($texts));
    }

    public function embedImages(string $apiKey, string $model, array $images, ?int $dimensions = null): Embeddings
    {
        $parts = array_map(fn (array $image): array => ['inline_data' => [
            'mime_type' => $image['mime'],
            'data' => base64_encode($image['data']),
        ]], array_values($images));

        return $this->batch($apiKey, $model, $parts, $dimensions, count($images) * self::TOKENS_PER_IMAGE);
    }

    /**
     * @param  list<array<string, mixed>>  $parts  one part per input
     */
    private function batch(string $apiKey, string $model, array $parts, ?int $dimensions, int $tokens): Embeddings
    {
        if ($parts === []) {
            return new Embeddings([], 0);
        }

        $requests = array_map(fn (array $part): array => array_filter([
            'model' => 'models/'.$model,
            'content' => ['parts' => [$part]],
            'output_dimensionality' => $dimensions,
        ], fn ($value): bool => $value !== null), $parts);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->post(self::URL.rawurlencode($model).':batchEmbedContents', ['requests' => $requests]);
        } catch (ConnectionException $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        if (! $response->successful()) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
        }

        $vectors = [];

        foreach ((array) $response->json('embeddings', []) as $embedding) {
            $values = $embedding['values'] ?? null;

            if (! is_array($values) || $values === []) {
                throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
            }

            $vectors[] = array_map('floatval', $values);
        }

        if (count($vectors) !== count($parts)) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
        }

        return new Embeddings($vectors, $tokens);
    }

    /** @param list<string> $texts */
    private function textTokens(array $texts): int
    {
        return (int) ceil(array_sum(array_map('mb_strlen', $texts)) / 4);
    }
}
