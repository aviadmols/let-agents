<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\RunsRetrieval;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * search:compare-embedders: the searches shoppers clicked from are the truth, the index and another
 * model are measured on them side by side, and the cost of switching is told.
 */
final class CompareEmbeddersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        // words folded into a short vector: texts that share words are near
        $words = new class implements Embedder, ImageEmbedder
        {
            public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                return new Embeddings(array_map(self::vector(...), $texts), count($texts) * 10);
            }

            public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
            {
                return $this->embed($provider, $model, $texts, $dimensions);
            }

            public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
            {
                return new Embeddings([], 0);
            }

            /** @return list<float> */
            public static function vector(string $text): array
            {
                $vector = array_fill(0, 48, 0.0);
                foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $word) {
                    if (mb_strlen($word) > 1) {
                        $vector[crc32($word) % 48] += 1.0;
                    }
                }

                return $vector;
            }
        };
        $this->app->instance(Embedder::class, $words);
        $this->app->instance(ImageEmbedder::class, $words);

        $this->shop = Shop::factory()->create();
        $this->inShop(function (): void {
            foreach (['1' => 'מקדחה נטענת 18V', '2' => 'מסור עגול לעץ', '3' => 'שמן לעץ טבעי לדק'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['title' => $title, 'description' => $title], 'price' => 99]);
            }
            foreach ([['מסור', 'p:2', 'מסור עגול לעץ', 7], ['שמן לדק', 'p:3', 'שמן לעץ טבעי לדק', 3], ['מקדחה', 'p:1', 'מקדחה נטענת 18V', 1], ['קטגוריה', 'k:9', 'כלים', 9]] as [$query, $item, $title, $clicks]) {
                SearchClick::query()->create(['id' => (string) Str::ulid(), 'shop_id' => $this->shop->id, 'day' => now()->subDays(3)->toDateString(), 'query' => $query, 'item' => $item, 'title' => $title, 'clicks' => $clicks]);
            }
        });
        app(RunsRetrieval::class)->index($this->shop->id);
    }

    public function test_the_trials_side_by_side_on_what_shoppers_clicked(): void
    {
        $this->artisan('search:compare-embedders', ['shop' => $this->shop->slug, '--queries' => 2])
            ->expectsOutputToContain('2 clicked searches')
            ->expectsOutputToContain('100.0%')
            ->expectsOutputToContain('search.semantic_min_similarity')
            ->expectsOutputToContain('Once:')
            ->expectsOutputToContain('A month:')
            ->assertSuccessful();
    }

    public function test_an_unknown_shop_lists_the_shops(): void
    {
        $this->artisan('search:compare-embedders', ['shop' => 'nobody'])
            ->expectsOutputToContain('No shop nobody')
            ->assertFailed();
    }

    public function test_a_shop_without_clicked_searches_says_so(): void
    {
        $this->artisan('search:compare-embedders', ['shop' => $this->shop->slug, '--days' => 1])
            ->expectsOutputToContain('no clicked searches')
            ->assertFailed();
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
