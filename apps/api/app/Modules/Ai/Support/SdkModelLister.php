<?php

namespace App\Modules\Ai\Support;

use Anthropic\Client as AnthropicClient;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Models\ModelInfo;
use Anthropic\RequestOptions;
use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Support\Drivers\ReplicateImages;
use GuzzleHttp\Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use OpenAI;
use OpenAI\Exceptions\ErrorException as OpenAiErrorException;
use OpenAI\Exceptions\TransporterException as OpenAiTransporterException;
use OpenAI\Responses\Models\RetrieveResponse;
use Throwable;

/**
 * Lists models with each provider's SDK: the official Anthropic PHP SDK and openai-php/client.
 */
final class SdkModelLister implements ListsProviderModels
{
    private const TIMEOUT_SECONDS = 20.0;

    public function list(AiProviderName $provider, string $apiKey): array
    {
        return match ($provider) {
            AiProviderName::Anthropic => $this->anthropic($apiKey),
            AiProviderName::OpenAi => $this->openAi($apiKey),
            AiProviderName::Gemini => $this->gemini($apiKey),
            AiProviderName::Replicate => $this->replicate($apiKey),
            AiProviderName::Voyage => $this->voyage($apiKey),
        };
    }

    /**
     * Voyage has no list of models to ask for: the key is checked with one word embedded by its smallest
     * model (inside the free tokens every account has), and its current embedding models are listed.
     *
     * @return list<array{id: string, name: string, created_at: ?string}>
     */
    private function voyage(string $apiKey): array
    {
        try {
            $response = Http::timeout((int) self::TIMEOUT_SECONDS)->withToken($apiKey)->acceptJson()
                ->post('https://api.voyageai.com/v1/embeddings', ['input' => ['ok'], 'model' => 'voyage-4-lite']);
        } catch (ConnectionException $e) {
            throw new ProviderCheckFailed(ProviderCheckFailed::UNREACHABLE, null, $e);
        }

        if (! $response->successful()) {
            throw ProviderCheckFailed::fromStatus($response->status());
        }

        return array_map(fn (string $id): array => ['id' => $id, 'name' => $id, 'created_at' => null], ['voyage-4-large', 'voyage-4', 'voyage-4-lite']);
    }

    /** @return list<array{id: string, name: string, created_at: ?string}> */
    private function anthropic(string $apiKey): array
    {
        $client = new AnthropicClient(apiKey: $apiKey, requestOptions: RequestOptions::with(timeout: self::TIMEOUT_SECONDS, maxRetries: 1));

        try {
            $page = $client->models->list(limit: 1000);
        } catch (APIStatusException $e) {
            throw ProviderCheckFailed::fromStatus($e->status, $e);
        } catch (APIConnectionException $e) {
            throw new ProviderCheckFailed(ProviderCheckFailed::UNREACHABLE, null, $e);
        }

        $models = [];

        foreach ($page->pagingEachItem() as $model) {
            /** @var ModelInfo $model */
            $models[] = [
                'id' => $model->id,
                'name' => $model->displayName,
                'created_at' => $model->createdAt->format(DATE_ATOM),
            ];
        }

        // The API already lists the most recent first.
        return $models;
    }

    /** @return list<array{id: string, name: string, created_at: ?string}> */
    private function openAi(string $apiKey): array
    {
        try {
            $response = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withHttpClient(new Client(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 10]))
                ->make()
                ->models()
                ->list();
        } catch (OpenAiErrorException $e) {
            throw ProviderCheckFailed::fromStatus($e->getStatusCode(), $e);
        } catch (OpenAiTransporterException $e) {
            throw new ProviderCheckFailed(ProviderCheckFailed::UNREACHABLE, null, $e);
        } catch (Throwable $e) {
            throw new ProviderCheckFailed(ProviderCheckFailed::PROVIDER_ERROR, null, $e);
        }

        $models = array_map(
            fn (RetrieveResponse $model): array => [
                'id' => $model->id,
                'name' => $model->id,
                'created_at' => $model->created ? date(DATE_ATOM, $model->created) : null,
            ],
            $response->data,
        );

        usort($models, fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $models;
    }

    /**
     * Google's Gemini API has no PHP SDK of its own, so a plain request: GET models with the key in
     * a header, never in the address, so it stays out of access logs.
     *
     * @return list<array{id: string, name: string, created_at: ?string}>
     */
    /**
     * Replicate: the key is checked against the account, and the picture models the platform knows
     * how to call are listed (ReplicateImages::INPUTS). Its whole catalogue is tens of thousands.
     *
     * @return list<array{id: string, name: string, created_at: ?string}>
     */
    private function replicate(string $apiKey): array
    {
        try {
            $response = Http::timeout((int) self::TIMEOUT_SECONDS)->withToken($apiKey)->get('https://api.replicate.com/v1/account');
        } catch (ConnectionException $e) {
            throw new ProviderCheckFailed(ProviderCheckFailed::UNREACHABLE, null, $e);
        }

        if (! $response->successful()) {
            throw ProviderCheckFailed::fromStatus($response->status());
        }

        return array_map(fn (string $id): array => ['id' => $id, 'name' => $id, 'created_at' => null], array_keys(ReplicateImages::INPUTS));
    }

    private function gemini(string $apiKey): array
    {
        try {
            $response = Http::timeout((int) self::TIMEOUT_SECONDS)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->get('https://generativelanguage.googleapis.com/v1beta/models', ['pageSize' => 1000]);
        } catch (ConnectionException $e) {
            throw new ProviderCheckFailed(ProviderCheckFailed::UNREACHABLE, null, $e);
        }

        if (! $response->successful()) {
            throw ProviderCheckFailed::fromStatus($response->status());
        }

        $models = [];

        foreach ((array) $response->json('models', []) as $model) {
            $id = preg_replace('#^models/#', '', (string) ($model['name'] ?? ''));

            if ($id !== '') {
                $models[] = ['id' => $id, 'name' => (string) ($model['displayName'] ?? $id), 'created_at' => null];
            }
        }

        return $models;
    }
}
