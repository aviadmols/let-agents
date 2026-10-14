<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Ai\Contracts\BatchChatModel;
use App\Modules\Ai\Contracts\BatchDriver;
use App\Modules\Ai\Contracts\BatchRequest;
use App\Modules\Ai\Contracts\BatchResult;
use App\Modules\Ai\Contracts\BatchState;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\Drivers\AnthropicBatches;
use App\Modules\Ai\Support\Drivers\OpenAiBatches;
use App\Modules\Ai\Support\ProviderBatchModel;
use App\Modules\Runs\Contracts\CallJournal;
use App\Modules\Runs\Models\RunCall;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Many questions at once through each provider's batch API: the requests go over as the provider
 * wants them, the state comes back in one word, and each answer is read as a reply or a reason.
 */
final class BatchDriversTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<RequestInterface> */
    private array $sent = [];

    public function test_openai_uploads_one_jsonl_file_opens_a_batch_on_it_and_reads_the_answers_back(): void
    {
        $driver = new OpenAiBatches($this->http([
            new Response(200, [], json_encode(['id' => 'file-in', 'object' => 'file', 'bytes' => 10, 'created_at' => 1, 'expires_at' => null, 'filename' => 'requests.jsonl', 'purpose' => 'batch', 'status' => 'processed', 'status_details' => null])),
            new Response(200, [], json_encode(self::openAiBatch('validating'))),
            new Response(200, [], json_encode(self::openAiBatch('in_progress', ['total' => 2, 'completed' => 1, 'failed' => 0]))),
            new Response(200, [], json_encode(self::openAiBatch('completed', ['total' => 2, 'completed' => 1, 'failed' => 1], 'file-out', 'file-err'))),
            new Response(200, [], json_encode(['custom_id' => 'a', 'response' => ['status_code' => 200, 'body' => ['choices' => [['message' => ['content' => '{"metal":"gold"}']]], 'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 20]]], 'error' => null])."\n"),
            new Response(200, [], json_encode(['custom_id' => 'b', 'response' => ['status_code' => 400, 'body' => ['error' => ['message' => 'too long']]], 'error' => null])."\n"),
        ]));

        $id = $driver->submit('sk-1', 'gpt-5.4-nano', [
            new BatchRequest('a', 'SYSTEM', '{"id":"a"}', 900),
            new BatchRequest('b', 'SYSTEM', '{"id":"b"}', 900, ['mime' => 'image/jpeg', 'bytes' => 'JPG']),
        ]);

        $this->assertSame('batch_1', $id);
        $upload = (string) $this->sent[0]->getBody();
        $this->assertStringContainsString('"custom_id":"a"', $upload);
        $this->assertStringContainsString('"url":"/v1/chat/completions"', $upload);
        $this->assertStringContainsString('"response_format":{"type":"json_object"}', $upload);
        $this->assertStringContainsString('data:image/jpeg;base64,'.base64_encode('JPG'), $upload, 'a picture rides along as a data address');
        $this->assertSame(['input_file_id' => 'file-in', 'endpoint' => '/v1/chat/completions', 'completion_window' => '24h'], json_decode((string) $this->sent[1]->getBody(), true));
        $this->assertSame('sk-1', trim(str_replace('Bearer', '', $this->sent[1]->getHeaderLine('Authorization'))));

        $state = $driver->state('sk-1', 'batch_1');
        $this->assertSame(BatchState::RUNNING, $state->status);
        $this->assertSame([2, 1], [$state->total, $state->done]);

        $results = iterator_to_array($driver->results('sk-1', 'batch_1'), false);
        $this->assertCount(2, $results);
        $this->assertSame(['metal' => 'gold'], $results[0]->reply?->data);
        $this->assertSame([300, 20], [$results[0]->reply?->inputTokens, $results[0]->reply?->outputTokens]);
        $this->assertNull($results[1]->reply);
        $this->assertSame('too long', $results[1]->error);
        $this->assertStringEndsWith('/files/file-err/content', (string) $this->sent[5]->getUri(), 'the refused ones are read from the error file');
    }

    public function test_anthropic_sends_the_requests_in_one_call_and_streams_the_answers_back(): void
    {
        $driver = new AnthropicBatches($this->http([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(self::anthropicBatch('in_progress', ['processing' => 2, 'succeeded' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0]))),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(self::anthropicBatch('ended', ['processing' => 0, 'succeeded' => 1, 'errored' => 1, 'canceled' => 0, 'expired' => 0]))),
            new Response(200, ['Content-Type' => 'application/x-jsonl'], json_encode(['custom_id' => 'a', 'result' => ['type' => 'succeeded', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'content' => [['type' => 'text', 'text' => "Sure:\n{\"ok\":true}"]], 'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 120, 'output_tokens' => 9]]]])."\n"
                .json_encode(['custom_id' => 'b', 'result' => ['type' => 'errored', 'error' => ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad request']]]])."\n"),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(self::anthropicBatch('ended', ['processing' => 0, 'succeeded' => 0, 'errored' => 2, 'canceled' => 0, 'expired' => 0]))),
        ]));

        $id = $driver->submit('sk-ant', 'claude-haiku-5-5', [new BatchRequest('a', 'SYSTEM', 'hello', 500), new BatchRequest('b', 'SYSTEM', 'hi', 500, ['mime' => 'image/png', 'bytes' => 'PNG'])]);

        $this->assertSame('msgbatch_1', $id);
        $body = json_decode((string) $this->sent[0]->getBody(), true);
        $this->assertSame('a', $body['requests'][0]['custom_id']);
        $this->assertSame('claude-haiku-5-5', $body['requests'][0]['params']['model']);
        $this->assertStringContainsString('one JSON object', $body['requests'][0]['params']['system']);
        $this->assertSame('image', $body['requests'][1]['params']['messages'][0]['content'][0]['type']);
        $this->assertSame('sk-ant', $this->sent[0]->getHeaderLine('x-api-key'));

        $this->assertSame(BatchState::ENDED, $driver->state('sk-ant', 'msgbatch_1')->status);

        $results = iterator_to_array($driver->results('sk-ant', 'msgbatch_1'), false);
        $this->assertSame(['ok' => true], $results[0]->reply?->data);
        $this->assertSame(120, $results[0]->reply?->inputTokens);
        $this->assertSame('bad request', $results[1]->error);

        $this->assertSame(BatchState::LOST, $driver->state('sk-ant', 'msgbatch_1')->status, 'ended with nothing answered is lost');
    }

    public function test_the_router_finds_the_providers_driver_and_key_and_journals_each_answer_collected(): void
    {
        $saved = AiProvider::query()->create(['provider' => 'openai', 'api_key' => 'sk-openai-1']);
        $saved->status = ProviderStatus::Connected;
        $saved->save();
        $driver = new class implements BatchDriver
        {
            public array $keys = [];

            public function provider(): AiProviderName
            {
                return AiProviderName::OpenAi;
            }

            public function submit(string $apiKey, string $model, array $requests): string
            {
                $this->keys[] = $apiKey;

                return 'b1';
            }

            public function state(string $apiKey, string $batchId): BatchState
            {
                return new BatchState(BatchState::ENDED, 1, 1, 0);
            }

            public function results(string $apiKey, string $batchId): iterable
            {
                yield new BatchResult('px-1', new ModelReply(['id' => '1'], 10, 2));
                yield new BatchResult('px-2', null, 'not_json');
            }
        };
        $model = new ProviderBatchModel([$driver], app(CallJournal::class));

        $this->assertTrue($model->supports(AiProviderName::OpenAi));
        $this->assertFalse($model->supports(AiProviderName::Gemini), 'no batch driver for Gemini');
        $this->assertSame('b1', $model->submit(AiProviderName::OpenAi, 'gpt-5.4-nano', [new BatchRequest('px-1', 's', 'u', 10)]));
        $this->assertSame(['sk-openai-1'], $driver->keys);

        $results = iterator_to_array($model->results(AiProviderName::OpenAi, 'gpt-5.4-nano', 'b1', 'SYSTEM', ['px-1' => '{"id":"1"}']), false);
        $this->assertCount(2, $results);
        $calls = RunCall::query()->orderBy('id')->get();
        $this->assertCount(2, $calls, 'each answer is a call in the journal');
        $this->assertSame('{"id":"1"}', $calls[0]->sent);
        $this->assertSame(['id' => '1'], json_decode((string) $calls[0]->received, true));
        $this->assertSame('not_json', $calls[1]->error);

        $this->expectException(ModelCallFailed::class);
        $model->submit(AiProviderName::Anthropic, 'claude-haiku-5-5', []);
    }

    public function test_the_container_routes_batches_through_the_registered_drivers(): void
    {
        $this->assertInstanceOf(ProviderBatchModel::class, app(BatchChatModel::class));
        $this->assertTrue(app(BatchChatModel::class)->supports(AiProviderName::Anthropic));
        $this->assertSame(0.5, BatchChatModel::DISCOUNT);
    }

    /** @param list<Response> $responses */
    private function http(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            $this->sent[] = $request;

            return $handler($request, $options);
        });

        return new Client(['handler' => $stack]);
    }

    /** @param array{total: int, completed: int, failed: int}|null $counts */
    private static function openAiBatch(string $status, ?array $counts = null, ?string $output = null, ?string $errors = null): array
    {
        return ['id' => 'batch_1', 'object' => 'batch', 'endpoint' => '/v1/chat/completions', 'input_file_id' => 'file-in', 'completion_window' => '24h', 'status' => $status,
            'output_file_id' => $output, 'error_file_id' => $errors, 'created_at' => 1, 'in_progress_at' => null, 'expires_at' => null, 'finalizing_at' => null, 'completed_at' => null,
            'failed_at' => null, 'expired_at' => null, 'cancelling_at' => null, 'cancelled_at' => null, 'request_counts' => $counts ?? ['total' => 0, 'completed' => 0, 'failed' => 0], 'metadata' => null];
    }

    /** @param array<string, int> $counts */
    private static function anthropicBatch(string $status, array $counts): array
    {
        return ['id' => 'msgbatch_1', 'type' => 'message_batch', 'processing_status' => $status, 'request_counts' => $counts, 'ended_at' => null, 'created_at' => '2026-10-14T00:00:00Z',
            'expires_at' => '2026-10-15T00:00:00Z', 'archived_at' => null, 'cancel_initiated_at' => null, 'results_url' => $status === 'ended' ? 'https://api.anthropic.com/v1/messages/batches/msgbatch_1/results' : null];
    }
}
