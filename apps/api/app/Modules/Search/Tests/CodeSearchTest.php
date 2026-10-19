<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A search for a product code (Rocks: "53077") shows that product, or nothing: by meaning a number is
 * near anything, so 24 rings and bracelets are not "found". A code the store has that the index does
 * not yet (added since it was built) has the index built again at once.
 */
final class CodeSearchTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    public array $asked = [];

    public function test_a_code_no_product_has_finds_nothing_by_meaning_and_one_the_index_lacks_rebuilds_it(): void
    {
        $this->assertTrue(HebrewSearch::looksLikeCode('53077'));
        $this->assertTrue(HebrewSearch::looksLikeCode('DDF-4850'));
        $this->assertFalse(HebrewSearch::looksLikeCode('14K'));
        $this->assertFalse(HebrewSearch::looksLikeCode('טבעת 5307'));

        LoadedIndex::forget();
        $test = $this;
        $this->app->instance(SemanticSearch::class, new class($test) implements SemanticSearch
        {
            public function __construct(private readonly CodeSearchTest $test) {}

            public function similarTo(string $source, string $sourceId, array $sources, int $limit): array
            {
                return [];
            }

            public function nearText(string $shopId, string $text, array $sources, int $limit): array
            {
                $this->test->asked[] = $text;

                return [['source' => 'product', 'external_id' => '1', 'similarity' => 0.9]];
            }

            public function lookAlike(string $productId, int $limit): array
            {
                return [];
            }

            public function picturesNearText(string $shopId, string $text, int $limit): array
            {
                return [];
            }

            public function picturesNearPhoto(string $shopId, string $mime, string $bytes, int $limit): array
            {
                return [];
            }

            public function picturesReady(): bool
            {
                return false;
            }

            public function ready(): bool
            {
                return true;
            }
        });

        $shop = Shop::factory()->create(['content_locale' => 'he']);
        $product = fn (string $id, string $sku) => CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'sku' => $sku, 'type' => 'simple', 'status' => 'publish', 'title' => "צמיד וינטג' - מק\"ט $sku", 'url' => "https://r.test/p/$id", 'price' => 1000, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
        app(TenantContext::class)->run($shop->id, fn () => $product('1', '53076'));
        app(BuildSearchIndex::class)->handle($shop->id);
        $search = fn (string $query): array => app(SearchCatalog::class)->handle($shop->id, $query, ['product'], false, null, true);

        $this->assertSame(['1'], array_column($search('53076')['groups']['product'], 'external_id'), 'the code: that product');
        $this->assertSame([], $search('99999')['groups']['product'], 'a code no product has: nothing, not what is near a number');
        $this->assertNotContains('99999', $this->asked, 'no meaning is asked for a code');

        // Added to the store after the index was built: the index is built again now.
        Queue::fake();
        app(TenantContext::class)->run($shop->id, fn () => $product('2', '53077'));
        $this->assertSame([], $search('53077')['groups']['product'], '53077 is not 53076: a number is never a typo');
        Queue::assertPushed(CallQueuedClosure::class);
    }
}
