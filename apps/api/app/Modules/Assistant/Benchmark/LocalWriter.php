<?php

namespace App\Modules\Assistant\Benchmark;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * The search box's writer, asked of a model on our own machine: the same system prompt, the same
 * input, the answer in the same JSON shape. For the writer benchmark only (config/local_ai.php);
 * nothing in production reaches it.
 *
 * Ollama gets the reply's JSON schema as its format, so the model can only write that shape; an
 * OpenAI-compatible server gets it as a strict json_schema. Either way the reply is checked here
 * again, field by field, before anyone reads it.
 */
final class LocalWriter
{
    /** The writer's reply, as answer_site.v4.md asks for it. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'found' => ['type' => 'boolean'],
            'answer' => ['type' => 'string'],
            'refs' => ['type' => 'array', 'items' => ['type' => 'string']],
            'picks' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['ref' => ['type' => 'string'], 'why' => ['type' => 'string']],
                'required' => ['ref', 'why'],
                'additionalProperties' => false,
            ]],
        ],
        'required' => ['found', 'answer', 'refs', 'picks'],
        'additionalProperties' => false,
    ];

    /** @var array<string, mixed> what the server said the last call used (OpenRouter adds its cost) */
    public array $usage = [];

    public static function enabled(): bool
    {
        return (bool) config('local_ai.enabled') && self::model() !== '';
    }

    public static function model(): string
    {
        return trim((string) config('local_ai.model'));
    }

    /** @throws ModelCallFailed when the server cannot be reached, refuses, or answers in another shape */
    public function write(string $system, string $input, int $maxOutputTokens): ModelReply
    {
        if (! self::enabled()) {
            throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
        }

        $url = rtrim((string) config('local_ai.url'), '/');
        $openAi = config('local_ai.api') === 'openai';
        $messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $input]];

        try {
            $key = trim((string) config('local_ai.key'));
            $response = Http::timeout((int) config('local_ai.timeout'))->acceptJson()->asJson()
                ->when($key !== '', fn ($http) => $http->withToken($key))
                ->post(
                    $openAi ? $url.'/v1/chat/completions' : $url.'/api/chat',
                    $openAi
                        ? ['model' => self::model(), 'messages' => $messages, 'temperature' => 0, 'max_tokens' => $maxOutputTokens,
                            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'strict' => true, 'schema' => self::SCHEMA]]]
                            // A hosted server's own options (OpenRouter: no reasoning, only providers that keep the schema, the cost).
                            + (array) config('local_ai.body')
                        : ['model' => self::model(), 'messages' => $messages, 'stream' => false, 'format' => self::SCHEMA, 'think' => (bool) config('local_ai.think'),
                            'options' => ['temperature' => 0, 'num_ctx' => (int) config('local_ai.context'), 'num_predict' => $maxOutputTokens]],
                );
        } catch (ConnectionException $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        if (! $response->successful()) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, new \RuntimeException('HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300)));
        }

        $body = (array) $response->json();
        $this->usage = (array) ($body['usage'] ?? []) + array_filter(['provider' => $body['provider'] ?? null]);
        $text = (string) ($openAi ? ($body['choices'][0]['message']['content'] ?? '') : ($body['message']['content'] ?? ''));

        return new ModelReply(
            self::validated($text),
            (int) ($openAi ? ($body['usage']['prompt_tokens'] ?? 0) : ($body['prompt_eval_count'] ?? 0)),
            (int) ($openAi ? ($body['usage']['completion_tokens'] ?? 0) : ($body['eval_count'] ?? 0)),
        );
    }

    /**
     * The reply's JSON, each field of the right type; a model that wrapped it in a code fence or
     * thought aloud first is read past that, but anything else in another shape is refused.
     *
     * @return array{found: bool, answer: string, refs: list<string>, picks: list<array{ref: string, why: string}>}
     *
     * @throws ModelCallFailed
     */
    public static function validated(string $text): array
    {
        $text = trim((string) preg_replace('~<think>.*?</think>~su', '', $text));
        $text = trim((string) preg_replace('~^```(?:json)?\s*|\s*```$~u', '', $text));

        try {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON, $e);
        }

        $refs = $data['refs'] ?? null;
        $picks = $data['picks'] ?? null;
        $shaped = is_array($data) && is_bool($data['found'] ?? null) && is_string($data['answer'] ?? null)
            && is_array($refs) && array_is_list($refs) && array_filter($refs, fn ($r): bool => ! is_string($r)) === []
            && is_array($picks) && array_is_list($picks)
            && array_filter($picks, fn ($p): bool => ! is_array($p) || ! is_string($p['ref'] ?? null) || ! is_string($p['why'] ?? null)) === [];

        if (! $shaped) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON, new \RuntimeException('Not the writer\'s shape: '.mb_substr($text, 0, 300)));
        }

        return [
            'found' => $data['found'],
            'answer' => $data['answer'],
            'refs' => $refs,
            'picks' => array_map(fn (array $p): array => ['ref' => $p['ref'], 'why' => $p['why']], $picks),
        ];
    }
}
