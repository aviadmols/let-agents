<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\BatchDriver;
use App\Modules\Ai\Contracts\BatchResult;
use App\Modules\Ai\Contracts\BatchState;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use GuzzleHttp\Client;
use OpenAI;
use OpenAI\Client as OpenAiClient;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * The OpenAI Batch API through openai-php/client: the requests go up as one JSONL file of chat
 * completions (each asking for a JSON object, as OpenAiChat does), a batch is opened on it with a
 * day to finish, and the answers come back as another file, one line per request.
 */
final class OpenAiBatches implements BatchDriver
{
    private const TIMEOUT_SECONDS = 120.0;

    private const ENDPOINT = '/v1/chat/completions';

    /** A test hands in its own client; otherwise one is made per call. */
    public function __construct(private readonly ?ClientInterface $http = null) {}

    public function provider(): AiProviderName
    {
        return AiProviderName::OpenAi;
    }

    public function submit(string $apiKey, string $model, array $requests): string
    {
        $lines = '';

        foreach ($requests as $request) {
            $lines .= json_encode([
                'custom_id' => $request->customId,
                'method' => 'POST',
                'url' => self::ENDPOINT,
                'body' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $request->system],
                        ['role' => 'user', 'content' => $request->image === null ? $request->user : [
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$request->image['mime'].';base64,'.base64_encode($request->image['bytes'])]],
                            ['type' => 'text', 'text' => $request->user],
                        ]],
                    ],
                    // a strict schema when the request has one (as OpenAiChat::structured sends it), a JSON object otherwise
                    'response_format' => $request->schema === null ? ['type' => 'json_object'] : ['type' => 'json_schema', 'json_schema' => ['name' => 'reply', 'strict' => true, 'schema' => $request->schema]],
                    'max_completion_tokens' => $request->maxOutputTokens,
                ] + ($request->effort === null ? [] : ['reasoning_effort' => $request->effort]),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        }

        // The file goes up named by its own path: OpenAI refuses a "filename" field beside it ("Additional properties are not
        // allowed", 20.11: every batch failed as provider_error), and reads a batch only from a .jsonl
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'openai-batch-'.bin2hex(random_bytes(8));
        $path = $dir.DIRECTORY_SEPARATOR.'requests.jsonl';
        $file = null;
        try {
            $client = $this->client($apiKey);
            mkdir($dir, 0700, true);
            file_put_contents($path, $lines);
            $file = fopen($path, 'rb');
            $uploaded = $client->files()->upload(['purpose' => 'batch', 'file' => $file]);
            $batch = $client->batches()->create(['input_file_id' => $uploaded->id, 'endpoint' => self::ENDPOINT, 'completion_window' => '24h']);
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            @unlink($path);
            @rmdir($dir);
        }

        return $batch->id;
    }

    public function state(string $apiKey, string $batchId): BatchState
    {
        try {
            $batch = $this->client($apiKey)->batches()->retrieve($batchId);
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $status = match ($batch->status) {
            'completed' => BatchState::ENDED,
            'failed', 'expired', 'cancelled' => BatchState::LOST,
            default => BatchState::RUNNING,
        };

        return new BatchState($status, (int) ($batch->requestCounts?->total ?? 0), (int) ($batch->requestCounts?->completed ?? 0), (int) ($batch->requestCounts?->failed ?? 0), $batch->status);
    }

    public function results(string $apiKey, string $batchId): iterable
    {
        try {
            $client = $this->client($apiKey);
            $batch = $client->batches()->retrieve($batchId);
            $files = array_values(array_filter([$batch->outputFileId, $batch->errorFileId]));
            $contents = array_map(fn (string $id): string => $client->files()->download($id), $files);
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        foreach ($contents as $content) {
            foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
                $row = json_decode($line, true);

                if (! is_array($row) || ! isset($row['custom_id'])) {
                    continue;
                }

                yield self::result($row);
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function result(array $row): BatchResult
    {
        $id = (string) $row['custom_id'];
        $body = $row['response']['body'] ?? null;

        if (($row['error'] ?? null) !== null || ! is_array($body) || (int) ($row['response']['status_code'] ?? 0) !== 200) {
            return new BatchResult($id, null, (string) ($row['error']['message'] ?? $body['error']['message'] ?? ModelCallFailed::PROVIDER_ERROR));
        }

        $data = json_decode((string) ($body['choices'][0]['message']['content'] ?? ''), true);

        if (! is_array($data)) {
            return new BatchResult($id, null, ModelCallFailed::NOT_JSON);
        }

        return new BatchResult($id, new ModelReply($data, (int) ($body['usage']['prompt_tokens'] ?? 0), (int) ($body['usage']['completion_tokens'] ?? 0)));
    }

    private function client(string $apiKey): OpenAiClient
    {
        return OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient($this->http ?? new Client(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 10]))
            ->make();
    }
}
