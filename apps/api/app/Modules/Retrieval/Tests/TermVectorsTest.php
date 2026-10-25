<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Retrieval\Contracts\TermVectors;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A shop's words embedded once each; a word it does not write read as its nearest ("מפוספס": "פסים"), above the floor only. */
final class TermVectorsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    public array $embedded = [];

    private const VECTORS = ['פסים' => [1.0, 0.0, 0.0], 'כיס' => [0.0, 1.0, 0.0], 'מפוספס' => [0.95, 0.15, 0.0]];

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->app->instance(Embedder::class, new class($test) implements Embedder
        {
            public function __construct(private readonly TermVectorsTest $test) {}

            public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                array_push($this->test->embedded, ...$texts);

                return new Embeddings(array_map(fn (string $t): array => TermVectorsTest::vector($t), $texts), 3 * count($texts));
            }
        });
        $this->app->instance(ImageEmbedder::class, new class implements ImageEmbedder
        {
            public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
            {
                return new Embeddings([], 0);
            }

            public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
            {
                throw new ModelCallFailed(ModelCallFailed::UNSUPPORTED);
            }
        });
    }

    /** @return list<float> */
    public static function vector(string $text): array
    {
        return self::VECTORS[$text] ?? [0.0, 0.0, 1.0];
    }

    public function test_each_word_is_embedded_once_and_an_unwritten_word_reads_as_the_nearest(): void
    {
        $shop = Shop::factory()->create();
        $terms = app(TermVectors::class);

        app(TenantContext::class)->run($shop->id, function () use ($shop, $terms): void {
            $this->assertSame(2, $terms->remember($shop->id, ['פסים', 'כיס']));
            $this->assertSame(0, $terms->remember($shop->id, ['פסים', 'כיס']), 'known words cost nothing again');
            $this->assertSame(['פסים', 'כיס'], $this->embedded);

            $near = $terms->nearest($shop->id, 'מפוספס', 1, 0.6);
            $this->assertSame('פסים', $near[0]['term'] ?? null);
            $this->assertGreaterThan(0.9, $near[0]['similarity']);
            $this->assertSame([], $terms->nearest($shop->id, 'מפוספס', 1, 0.999), 'below the floor: nothing');
        });
    }
}
