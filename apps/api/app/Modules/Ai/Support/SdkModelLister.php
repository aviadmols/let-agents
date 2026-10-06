<?php

namespace App\Modules\Ai\Support;

use Anthropic\Client as AnthropicClient;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Models\ModelInfo;
use Anthropic\RequestOptions;
use App\Modules\Ai\Contracts\ListsProviderModels;
use App\Modules\Ai\Enums\AiProviderName;
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
        };
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
