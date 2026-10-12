<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "More like what you searched for": the shopper searched "חולצה לבנה" and opened one of the white
 * shirts; its page offers the other white shirts. Only when the page's product is among what that
 * search finds. The search itself (spelling only, no vector, nothing counted) is the Search
 * module's, tested there (ProductsForQueryTest).
 */
final class SearchedProductsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_ssssssssssssssssssssssssssssssssssssssssssssssss';

    private Shop $shop;

    /** @var list<string> what was searched for */
    public array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['1' => 'חולצה לבנה עם כיס', '2' => 'חולצה לבנה קלאסית', '3' => 'חולצת פולו לבנה', '4' => 'חולצה שחורה'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'image_url' => "https://www.store.test/p/{$id}.jpg", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [], 'price' => 99]);
            }
        });

        $test = $this;
        $this->app->instance(ProductsForQuery::class, new class($test) implements ProductsForQuery
        {
            public function __construct(private readonly SearchedProductsTest $test) {}

            public function find(string $shopId, string $query, int $limit): array
            {
                $this->test->asked[] = $query;

                return $query === 'חולצה לבנה' ? ['2', '1', '3'] : [];
            }
        });
    }

    public function test_the_page_offers_the_rest_of_the_search_that_brought_the_shopper(): void
    {
        $data = $this->searched('חולצה לבנה', '1')->assertOk()->json('data');

        $this->assertSame('חולצה לבנה', $data['query']);
        $this->assertSame(['2', '3'], array_column($data['products'], 'id'), 'in the search\'s order, never the page itself');
        $this->assertSame(['חולצה לבנה'], $this->asked);
    }

    public function test_a_search_that_did_not_find_this_product_offers_nothing(): void
    {
        // They searched white shirts earlier, then came to the black one some other way.
        $this->assertSame([], $this->searched('חולצה לבנה', '4')->assertOk()->json('data.products'));
    }

    public function test_only_the_shops_site_may_ask_and_the_shop_may_turn_it_off(): void
    {
        $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/searched', server: ['HTTP_ORIGIN' => 'https://evil.test', 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['q' => 'חולצה', 'id' => '1']))->assertForbidden();
        $this->searched('', '1')->assertStatus(422);

        Features::override('widget.searched_products', false, $this->shop->id);
        $this->searched('חולצה לבנה', '1')->assertForbidden();
        $this->assertSame([], $this->asked, 'nothing searched for a request refused');
    }

    private function searched(string $query, string $id): TestResponse
    {
        return $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/searched', server: [
            'HTTP_ORIGIN' => 'https://www.store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['q' => $query, 'id' => $id, 'locale' => 'he'], JSON_UNESCAPED_UNICODE));
    }
}
