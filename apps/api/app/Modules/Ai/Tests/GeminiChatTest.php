<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Support\Drivers\GeminiChat;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Gemini writes JSON answers: the request it is sent, the reply read back, its tokens and its failures. */
final class GeminiChatTest extends TestCase
{
    public function test_it_sends_the_instructions_the_schema_and_the_thinking_level_and_reads_the_reply(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'thinking…', 'thought' => true], ['text' => '{"answer": "כן, יש לנו."}']]]]],
            'usageMetadata' => ['promptTokenCount' => 1200, 'cachedContentTokenCount' => 1000, 'candidatesTokenCount' => 40, 'thoughtsTokenCount' => 60],
        ])]);

        $reply = (new GeminiChat)->structured('key', 'gemini-3.8-flash', 'You answer.', '{"question": "יש?"}', ['type' => 'object'], 800, 'minimal');

        $this->assertSame(['answer' => 'כן, יש לנו.'], $reply->data, 'its thoughts are never the answer');
        $this->assertSame(200, $reply->inputTokens, 'the prompt read from Google\'s cache is counted apart');
        $this->assertSame(1000, $reply->cacheReadTokens);
        $this->assertSame(100, $reply->outputTokens, 'thinking is paid as output');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_ends_with($request->url(), 'models/gemini-3.8-flash:generateContent')
                && $request->header('x-goog-api-key') === ['key']
                && ! str_contains($request->url(), 'key=')
                && $body['systemInstruction']['parts'][0]['text'] === 'You answer.'
                && $body['generationConfig']['responseJsonSchema'] === ['type' => 'object']
                && $body['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low']
                && $body['generationConfig']['maxOutputTokens'] === 800;
        });
    }

    public function test_a_quota_or_a_reply_that_is_no_json_fails_as_such(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);
        try {
            (new GeminiChat)->json('key', 'gemini-3.8-flash', 's', 'u', 100);
            $this->fail('a quota is a failure');
        } catch (ModelCallFailed $e) {
            $this->assertSame(ModelCallFailed::RATE_LIMITED, $e->reason);
        }

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'no json here']]]]]])]);
        $this->expectException(ModelCallFailed::class);
        (new GeminiChat)->json('key', 'gemini-3.8-flash', 's', 'u', 100);
    }
}
