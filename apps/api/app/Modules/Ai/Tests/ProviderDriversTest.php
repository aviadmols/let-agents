<?php

namespace App\Modules\Ai\Tests;

use App\Modules\Ai\Contracts\ChatDriver;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\EmbeddingDriver;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Models\AiProvider;
use App\Modules\Ai\Support\ProviderChatModel;
use App\Modules\Ai\Support\ProviderEmbedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A provider is a driver. The caller names a provider and a model from its settings; the router
 * finds the driver and the key, and a provider with no driver or no key is refused plainly.
 */
final class ProviderDriversTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_routes_chat_and_embeddings_through_the_registered_drivers(): void
    {
        $this->assertInstanceOf(ProviderChatModel::class, app(ChatModel::class));
        $this->assertInstanceOf(ProviderEmbedder::class, app(Embedder::class));
    }

    public function test_a_request_reaches_the_driver_of_its_provider_with_that_providers_key(): void
    {
        $this->connected('openai', 'sk-openai-1111');
        $this->connected('anthropic', 'sk-ant-2222');

        $calls = [];
        $chat = new ProviderChatModel([$this->chatDriver(AiProviderName::OpenAi, $calls), $this->chatDriver(AiProviderName::Anthropic, $calls)]);

        $reply = $chat->json(AiProviderName::Anthropic, 'claude-haiku-4-5', 'system', 'user', 100);

        $this->assertSame(['driver' => 'anthropic'], $reply->data);
        $this->assertSame([['anthropic', 'sk-ant-2222', 'claude-haiku-4-5']], $calls);

        $embedded = [];
        $embedder = new ProviderEmbedder([$this->embeddingDriver($embedded)]);
        $result = $embedder->embed(AiProviderName::OpenAi, 'text-embedding-3-small', ['א', 'ב']);

        $this->assertSame([[1.0, 0.0], [1.0, 0.0]], $result->vectors);
        $this->assertSame(2, $result->dimensions());
        $this->assertSame([['sk-openai-1111', 'text-embedding-3-small', 2]], $embedded);
        $this->assertSame(0, $embedder->embed(AiProviderName::OpenAi, 'x', [])->inputTokens, 'nothing to embed costs nothing');
    }

    public function test_a_provider_without_a_driver_or_without_a_key_is_refused(): void
    {
        $this->connected('anthropic', 'sk-ant-2222');
        $calls = [];

        $this->assertRefused(ModelCallFailed::UNSUPPORTED, fn () => (new ProviderEmbedder([]))->embed(AiProviderName::Anthropic, 'voyage-3', ['א']));
        $this->assertRefused(ModelCallFailed::NO_KEY, fn () => (new ProviderChatModel([$this->chatDriver(AiProviderName::OpenAi, $calls)]))->json(AiProviderName::OpenAi, 'gpt', 's', 'u', 10));
        $this->assertSame([], $calls);
    }

    /** A saved key starts untested; a test marks it connected the way a passing check would. */
    private function connected(string $provider, string $key): void
    {
        $saved = AiProvider::query()->create(['provider' => $provider, 'api_key' => $key]);
        $saved->status = ProviderStatus::Connected;
        $saved->save();
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("expected {$reason}");
        } catch (ModelCallFailed $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function chatDriver(AiProviderName $provider, array &$calls): ChatDriver
    {
        return new class($provider, $calls) implements ChatDriver
        {
            public function __construct(private AiProviderName $name, private array &$calls) {}

            public function provider(): AiProviderName
            {
                return $this->name;
            }

            public function json(string $apiKey, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls[] = [$this->name->value, $apiKey, $model];

                return new ModelReply(['driver' => $this->name->value], 10, 5);
            }
        };
    }

    private function embeddingDriver(array &$calls): EmbeddingDriver
    {
        return new class($calls) implements EmbeddingDriver
        {
            public function __construct(private array &$calls) {}

            public function provider(): AiProviderName
            {
                return AiProviderName::OpenAi;
            }

            public function embed(string $apiKey, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                $this->calls[] = [$apiKey, $model, count($texts)];

                return new Embeddings(array_map(fn (): array => [1.0, 0.0], $texts), 3);
            }
        };
    }
}
