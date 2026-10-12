<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What a product page may ask of the search on load: spelling only, no vector, nothing counted. */
final class ProductsForQueryTest extends TestCase
{
    use RefreshDatabase;

    public int $embeddings = 0;

    public function test_spelling_only_and_nothing_counted(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'חולצה לבנה עם כיס', '2' => 'חולצה לבנה קלאסית', '3' => 'חולצה שחורה'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [], 'price' => 99]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $test = $this;
        $this->app->instance(Embedder::class, new class($test) implements Embedder
        {
            public function __construct(private readonly ProductsForQueryTest $test) {}

            public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                $this->test->embeddings++;

                return new Embeddings([], 0);
            }
        });

        $found = app(TenantContext::class)->run($shop->id, fn (): array => app(ProductsForQuery::class)->find($shop->id, 'חולצה לבנה', 5));

        $this->assertEqualsCanonicalizing(['1', '2'], $found);
        $this->assertSame(0, $this->embeddings, 'no vector on a page load');
        $this->assertSame(0, app(TenantContext::class)->run($shop->id, fn (): int => SearchTerm::query()->count()), 'nothing counted');
    }
}
