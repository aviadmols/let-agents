<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Support\Drivers\XaiChat;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** xAI's Grok writes JSON answers: the request it is sent, the reply read back, its tokens and its failures. */
final class XaiChatTest extends TestCase
{
    public function test_it_sends_the_instructions_the_schema_and_the_effort_and_reads_the_reply(): void
    {
        Http::fake(['api.x.ai/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => '{"answer": "כן, יש לנו."}']]],
            // the reasoning counted apart from the reply: the total is more than the prompt and the reply
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 40, 'total_tokens' => 1300, 'prompt_tokens_details' => ['cached_tokens' => 1000]],
        ])]);

        $reply = (new XaiChat)->structured('xai-key', 'grok-4.3', 'You answer.', '{"question": "יש?"}', ['type' => 'object'], 800, 'minimal');

        $this->assertSame(['answer' => 'כן, יש לנו.'], $reply->data);
        $this->assertSame(200, $reply->inputTokens, 'the prompt read from xAI\'s cache is counted apart');
        $this->assertSame(1000, $reply->cacheReadTokens);
        $this->assertSame(100, $reply->outputTokens, 'reasoning counted apart is paid as output');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === XaiChat::URL
                && $request->header('Authorization') === ['Bearer xai-key']
                && $body['messages'][0] === ['role' => 'system', 'content' => 'You answer.']
                && $body['response_format']['type'] === 'json_schema'
                && $body['response_format']['json_schema']['strict'] === true
                && $body['response_format']['json_schema']['schema'] === ['type' => 'object']
                && $body['reasoning_effort'] === 'low'
                && $body['max_completion_tokens'] === 800;
        });
    }

    public function test_a_model_that_takes_no_effort_nor_that_limits_name_is_asked_once_more_without_them(): void
    {
        $sent = [];
        Http::fake(['api.x.ai/*' => function (Request $request) use (&$sent) {
            $sent[] = $body = $request->data();
            if (isset($body['reasoning_effort'])) {
                return Http::response(['error' => 'Model grok-4.3 does not support parameter reasoning_effort.'], 400);
            }
            if (isset($body['max_completion_tokens'])) {
                return Http::response(['error' => 'Unknown argument max_completion_tokens'], 400);
            }

            return Http::response(['choices' => [['message' => ['content' => '{"ok": true}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]);
        }]);

        $reply = (new XaiChat)->json('k', 'grok-4.3', 's', 'u', 300, 'medium');

        $this->assertSame(['ok' => true], $reply->data);
        $this->assertSame(5, $reply->outputTokens, 'a total of the prompt and the reply adds nothing');
        $this->assertCount(3, $sent);
        $this->assertSame(300, $sent[2]['max_tokens']);
        $this->assertArrayNotHasKey('reasoning_effort', $sent[2]);
        $this->assertSame(['type' => 'json_object'], $sent[0]['response_format'], 'no schema: a JSON object');
    }

    public function test_a_rate_limit_or_a_reply_that_is_no_json_fails_as_such(): void
    {
        Http::fake(['api.x.ai/*' => Http::response(['error' => 'rate limited'], 429)]);
        try {
            (new XaiChat)->json('k', 'grok-4.3', 's', 'u', 100);
            $this->fail('a rate limit is a failure');
        } catch (ModelCallFailed $e) {
            $this->assertSame(ModelCallFailed::RATE_LIMITED, $e->reason);
        }

        Http::fake(['api.x.ai/*' => Http::response(['choices' => [['message' => ['content' => 'no json here']]]])]);
        $this->expectException(ModelCallFailed::class);
        (new XaiChat)->json('k', 'grok-4.3', 's', 'u', 100);
    }
}
