<?php

namespace App\Modules\Ai\Support\Drivers;

use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\StructuredChatDriver;
use App\Modules\Ai\Contracts\VisionDriver;
use App\Modules\Ai\Enums\AiProviderName;
use GuzzleHttp\Client;
use OpenAI;
use OpenAI\Exceptions\RateLimitException;
use RuntimeException;
use Throwable;

/** Chat completions through openai-php/client, asking for a JSON object. */
final class OpenAiChat implements StructuredChatDriver, VisionDriver
{
    private const TIMEOUT_SECONDS = 45.0;

    /** A reply allowed many tokens (a store's tag bank reviewed, products tagged) is given longer: this many tokens a second, up to MAX_TIMEOUT_SECONDS. */
    private const TOKENS_PER_SECOND = 40;

    private const MAX_TIMEOUT_SECONDS = 300.0;

    public function provider(): AiProviderName
    {
        return AiProviderName::OpenAi;
    }

    public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, $reasoningEffort);
    }

    public function structured(string $apiKey, string $model, string $system, string $user, array $schema, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, $user, $maxOutputTokens, $reasoningEffort, ['type' => 'json_schema', 'json_schema' => ['name' => 'reply', 'strict' => true, 'schema' => $schema]]);
    }

    public function jsonWithImage(string $apiKey, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
    {
        return $this->send($apiKey, $model, $system, [
            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes)]],
            ['type' => 'text', 'text' => $user],
        ], $maxOutputTokens, $reasoningEffort);
    }

    /**
     * @param  string|list<array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $format  a strict json_schema; a JSON object otherwise
     */
    private function send(string $apiKey, string $model, string $system, string|array $content, int $maxOutputTokens, ?string $reasoningEffort, ?array $format = null): ModelReply
    {
        // A rate limit (429) is waited out and asked again, twice: as the provider says (Retry-After), a few seconds for a
        // shopper's short answer, up to 20 for background work (29.10: a shop's rule failed on one 429 and never came back).
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->once($apiKey, $model, $system, $content, $maxOutputTokens, $reasoningEffort, $format);
            } catch (RateLimitException $e) {
                // Out of credit is a 429 too, and no wait mends it: said in the provider's own words, so the key is marked
                // failed and the operator's panel says so (KeyFailure). The client's message alone named only a rate
                // limit (29.10: every OpenAI call failed for hours, and no alert said why).
                $body = (string) $e->response->getBody();
                if (self::outOfCredit($body)) {
                    throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, new RuntimeException('OpenAI insufficient_quota: '.mb_substr($body, 0, 300), 429, $e));
                }
                if ($attempt >= self::RATE_RETRIES) {
                    throw new ModelCallFailed(ModelCallFailed::RATE_LIMITED, $e);
                }
                $said = (float) ($e->response->getHeaderLine('retry-after') ?: 0);
                $cap = $maxOutputTokens > self::SHORT_REPLY_TOKENS ? 20.0 : 3.0;
                usleep((int) (min($cap, max($said, 2.0 * ($attempt + 1))) * 1_000_000));
            }
        }
    }

    /** Whether a 429's body says the account is out of credit, not that it asked too fast. */
    public static function outOfCredit(string $body): bool
    {
        $error = (array) (json_decode($body, true)['error'] ?? []);

        return in_array('insufficient_quota', [$error['type'] ?? null, $error['code'] ?? null], true)
            || preg_match('/insufficient_quota|credit_balance|no credits remaining|exceeded your current quota/i', $body) === 1;
    }

    /** Asked again after a rate limit at most this many times. */
    private const RATE_RETRIES = 2;

    /** A shopper's answer: waited for briefly, a few seconds at most. */
    private const SHORT_REPLY_TOKENS = 2000;

    /**
     * @param  string|list<array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $format
     *
     * @throws RateLimitException
     */
    private function once(string $apiKey, string $model, string $system, string|array $content, int $maxOutputTokens, ?string $reasoningEffort, ?array $format): ModelReply
    {
        try {
            $response = OpenAI::factory()
                ->withApiKey($apiKey)
                // a short reply (a shopper's answer, up to 1,800 tokens) waits as it did; a long one, background work, longer
                ->withHttpClient(new Client(['timeout' => min(self::MAX_TIMEOUT_SECONDS, max(self::TIMEOUT_SECONDS, $maxOutputTokens / self::TOKENS_PER_SECOND)), 'connect_timeout' => 10]))
                ->make()
                ->chat()
                ->create(array_filter([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $content],
                    ],
                    'response_format' => $format ?? ['type' => 'json_object'],
                    'max_completion_tokens' => $maxOutputTokens,
                    'reasoning_effort' => $reasoningEffort,
                ], fn ($value): bool => $value !== null && $value !== ''));
        } catch (RateLimitException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR, $e);
        }

        $data = json_decode((string) ($response->choices[0]->message->content ?? ''), true);

        if (! is_array($data)) {
            throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
        }

        return new ModelReply($data, (int) ($response->usage?->promptTokens ?? 0), (int) ($response->usage?->completionTokens ?? 0));
    }
}
