<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Retrieval\Support\QueryVectors;
use Tests\TestCase;

/**
 * A shopper's words are embedded as a search query by a model that takes one (gemini-embedding-2, as
 * picture search sends them), and as they are by any other; a real failure is never hidden by a retry.
 */
final class QueryAsSearchTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;

        $this->app->instance(Embedder::class, new class($test) implements Embedder
        {
            public function __construct(private readonly QueryAsSearchTest $test) {}

            public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                $this->test->called('plain:'.$provider->value);

                return new Embeddings([[1.0, 0.0]], 5);
            }
        });
        $this->app->instance(ImageEmbedder::class, new class($test) implements ImageEmbedder
        {
            public function __construct(private readonly QueryAsSearchTest $test) {}

            public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
            {
                return new Embeddings([], 0);
            }

            public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
            {
                if ($provider === AiProviderName::OpenAi) {
                    throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
                }
                if ($provider === AiProviderName::Anthropic) {
                    throw new ModelCallFailed('no_key');
                }
                $this->test->called('query:'.$provider->value.':'.($query ? 'yes' : 'no'));

                return new Embeddings([[0.0, 1.0]], 5);
            }
        });
    }

    public function called(string $what): void
    {
        $this->calls[] = $what;
    }

    public function test_a_model_that_takes_a_search_query_is_told_it_is_one(): void
    {
        app(QueryVectors::class)->asQuery(AiProviderName::Gemini, 'gemini-embedding-2', ['טבעת זהב'], 768);

        $this->assertSame(['query:gemini:yes'], $this->calls);
    }

    public function test_any_other_model_gets_the_words_as_they_are(): void
    {
        app(QueryVectors::class)->asQuery(AiProviderName::OpenAi, 'text-embedding-3-small', ['טבעת זהב'], null);

        $this->assertSame(['plain:openai'], $this->calls);
    }

    public function test_a_real_failure_is_not_retried_another_way(): void
    {
        try {
            app(QueryVectors::class)->asQuery(AiProviderName::Anthropic, 'x', ['טבעת'], null);
            $this->fail('the failure should reach the caller');
        } catch (ModelCallFailed $e) {
            $this->assertSame('no_key', $e->reason);
        }

        $this->assertSame([], $this->calls, 'no second call that would hide it');
    }
}
