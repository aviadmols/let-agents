<?php

namespace App\Modules\Ai\Support\Drivers;

use Anthropic\Client;
use Anthropic\Messages\Batches\MessageBatchSucceededResult;
use Anthropic\RequestOptions;
use App\Modules\Ai\Contracts\BatchDriver;
use App\Modules\Ai\Contracts\BatchRequest;
use App\Modules\Ai\Contracts\BatchResult;
use App\Modules\Ai\Contracts\BatchState;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Anthropic Message Batches through the official SDK: the requests go over in one call, each the
 * same message AnthropicChat would send (one JSON object asked for in the system prompt), and the
 * answers are streamed back when the batch has ended.
 */
final class AnthropicBatches implements BatchDriver
{
    private const TIMEOUT_SECONDS = 120.0;

    private const JSON_ONLY = "\n\nAnswer with one JSON object and nothing else: no prose, no code fence.";

    /** A test hands in its own transport; otherwise the SDK finds one. */
    public function __construct(private readonly ?ClientInterface $http = null) {}

    public function provider(): AiProviderName
    {
        return AiProviderName::Anthropic;
    }

    public function submit(string $apiKey, string $model, array $requests): string
    {
        $rows = array_map(fn (BatchRequest $request): array => ['custom_id' => $request->customId, 'params' => [
            'model' => $model,
            'max_tokens' => $request->maxOutputTokens,
            'system' => $request->system.self::JSON_ONLY,
            'messages' => [['role' => 'user', 'content' => $request->image === null ? $request->user : [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $request->image['mime'], 'data' => base64_encode($request->image['bytes'])]],
                ['type' => 'text', 'text' => $request->user],
            ]]],
        ]], $requests);

        try {
            $batch = $this->client($apiKey)->messages->batches->create(requests: $rows);
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        return $batch->id;
    }

    public function state(string $apiKey, string $batchId): BatchState
    {
        try {
            $batch = $this->client($apiKey)->messages->batches->retrieve($batchId);
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $counts = $batch->requestCounts;
        $failed = $counts->errored + $counts->canceled + $counts->expired;
        $total = $counts->processing + $counts->succeeded + $failed;
        // Anthropic ends a batch whatever happened to its requests; "lost" is every request lost.
        $status = $batch->processingStatus !== 'ended' ? BatchState::RUNNING : ($total > 0 && $counts->succeeded === 0 ? BatchState::LOST : BatchState::ENDED);

        return new BatchState($status, $total, $counts->succeeded, $failed, $batch->processingStatus);
    }

    public function results(string $apiKey, string $batchId): iterable
    {
        try {
            $stream = $this->client($apiKey)->messages->batches->resultsStream($batchId);
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        foreach ($stream as $row) {
            $result = $row->result;

            if (! $result instanceof MessageBatchSucceededResult) {
                yield new BatchResult($row->customID, null, $result->type === 'errored' ? (string) ($result->error->error->message ?? $result->type) : $result->type);

                continue;
            }

            $text = '';
            foreach ($result->message->content as $block) {
                if (($block->type ?? null) === 'text') {
                    $text .= $block->text;
                }
            }

            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

            yield is_array($data)
                ? new BatchResult($row->customID, new ModelReply($data, $result->message->usage->inputTokens, $result->message->usage->outputTokens))
                : new BatchResult($row->customID, null, ModelCallFailed::NOT_JSON);
        }
    }

    private function client(string $apiKey): Client
    {
        return new Client(apiKey: $apiKey, requestOptions: RequestOptions::with(timeout: self::TIMEOUT_SECONDS, maxRetries: 1, transporter: $this->http));
    }
}
