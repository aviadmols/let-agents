<?php

namespace App\Modules\Ai\Support\Drivers;

use Anthropic\Client;
use Anthropic\Messages\WebSearchResultBlock;
use Anthropic\Messages\WebSearchTool20250305;
use Anthropic\RequestOptions;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\StructuredChatDriver;
use App\Modules\Ai\Contracts\VisionDriver;
use App\Modules\Ai\Contracts\WebAnswer;
use App\Modules\Ai\Contracts\WebSearchDriver;
use App\Modules\Ai\Enums\AiProviderName;
use Throwable;

/**
 * Claude through the official Anthropic SDK, one message at a time. Bulk analysis still goes
 * through the Batch API in Enrichment; this is for work an operator chose to run directly.
 *
 * Claude has no JSON mode without a schema, so the system prompt asks for one JSON object and
 * the reply is read from its first brace to its last.
 *
 * A model of the 4.6 and 5 families thinks on its own (adaptive) at a medium effort unless told otherwise: the effort a
 * caller asks for is passed on (CLAUDE.md: adaptive thinking and low effort for bulk roles). An older model (Haiku 4.5)
 * takes neither, and is sent as before.
 */
final class AnthropicChat implements StructuredChatDriver, VisionDriver, WebSearchDriver
{
    private const TIMEOUT_SECONDS = 60.0;

    /** A model that searches waits for its pages. */
    private const SEARCH_TIMEOUT_SECONDS = 180.0;

    private const JSON_ONLY = "\n\nAnswer with one JSON object and nothing else: no prose, no code fence.";

    public function provider(): AiProviderName
    {
        return AiProviderName::Anthropic;
    }

    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, null, $reasoningEffort);
    }

    public function structured(string $apiKey, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, $schema, $reasoningEffort);
    }

    /**
     * How a model is asked to think: adaptive thinking at the effort asked for, on a model that takes it; nothing on one
     * that does not (budget_tokens models). "minimal" is low here.
     *
     * @return array{thinking: array{type: string}, effort: string}|array{}
     */
    public static function tuning(string $model, ?string $effort): array
    {
        $effort = $effort === 'minimal' ? 'low' : $effort;

        if ($effort === null || ! in_array($effort, ['low', 'medium', 'high'], true) || preg_match('/^claude-(opus|sonnet|haiku|fable)-(4-[6-9]|5)/', $model) !== 1) {
            return [];
        }

        return ['thinking' => ['type' => 'adaptive'], 'effort' => $effort];
    }

    public function jsonWithImage(string $apiKey, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]],
            ['type' => 'text', 'text' => $user],
        ], $maxOutputTokens);
    }

    /**
     * Claude with the web search tool: it searches as many times as it needs, up to $maxSearches,
     * and answers with one JSON object. The addresses the searches returned come back with it, so a
     * source the model names can be told from one it invented.
     */
    public function research(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, int $maxSearches): WebAnswer
    {
        try {
            $message = (new Client(apiKey: $apiKey, requestOptions: RequestOptions::with(timeout: self::SEARCH_TIMEOUT_SECONDS, maxRetries: 1)))
                ->messages
                ->create(
                    maxTokens: $maxOutputTokens,
                    messages: [['role' => 'user', 'content' => $user]],
                    model: $model,
                    system: $system.self::JSON_ONLY,
                    tools: [WebSearchTool20250305::with(maxUses: $maxSearches)],
                );
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $text = '';
        $urls = [];

        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }

            if (($block->type ?? null) === 'web_search_tool_result' && is_array($block->content)) {
                foreach ($block->content as $result) {
                    if ($result instanceof WebSearchResultBlock) {
                        $urls[] = $result->url;
                    }
                }
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        return new WebAnswer(
            new ModelReply($data, $message->usage->inputTokens, $message->usage->outputTokens),
            array_values(array_unique($urls)),
            (int) ($message->usage->serverToolUse?->webSearchRequests ?? 0),
        );
    }

    /**
     * @param  string|list<array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $schema  held to it by the API (output_config.format), so it always parses
     */
    private function send(string $apiKey, string $model, string $system, string|array $content, int $maxOutputTokens, ?array $schema = null, ?string $effort = null): ModelReply
    {
        $tune = self::tuning($model, $effort);
        $output = array_filter(['format' => $schema === null ? null : ['type' => 'json_schema', 'schema' => $schema], 'effort' => $tune['effort'] ?? null]);

        try {
            $message = (new Client(apiKey: $apiKey, requestOptions: RequestOptions::with(timeout: self::TIMEOUT_SECONDS, maxRetries: 1)))
                ->messages
                ->create(
                    maxTokens: $maxOutputTokens,
                    messages: [['role' => 'user', 'content' => $content]],
                    model: $model,
                    outputConfig: $output === [] ? null : $output,
                    system: $system.self::JSON_ONLY,
                    thinking: $tune['thinking'] ?? null,
                );
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $text = '';

        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        return new ModelReply($data, $message->usage->inputTokens, $message->usage->outputTokens);
    }
}
