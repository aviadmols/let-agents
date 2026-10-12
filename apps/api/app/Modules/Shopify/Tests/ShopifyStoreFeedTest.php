<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\SyncCatalog;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Shopify reader proven by the real catalog sync: if the records it builds are in the wrong
 * shape, the rows below come out wrong.
 */
final class ShopifyStoreFeedTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private ShopifyInstall $install;

    /** The details query answers with an error, as for a store whose app may not read a field. */
    private bool $failDetails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.version' => '2026-07']);

        $this->shop = Shop::factory()->create(['platform' => ShopPlatform::Shopify, 'domain' => 'kli.co.il', 'currency' => 'ILS']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id,
            'platform' => 'shopify',
            'site_url' => 'https://kli.co.il',
            'access_token' => 'rgt_'.str_repeat('b', 48),
        ]));
        $this->install = ShopifyInstall::query()->create([
            'shop_id' => $this->shop->id,
            'shop_domain' => 'kli-tools.myshopify.com',
            'access_token' => 'shpat_test',
            'installed_at' => now(),
        ]);

        Http::fake(fn (Request $request) => str_contains($request->url(), '/pages/')
            // The storefront page a theme builds from sections: its text is only on the page itself.
            ? Http::response('<html><body><header>תפריט</header><main><h1>ביטול עסקה</h1><p>ניתן לבטל עסקה תוך 14 יום.</p><form><input name="id"><button>שליחה</button></form></main><footer>זכויות</footer></body></html>', 200, ['Content-Type' => 'text/html'])
            : $this->respond($request));
    }

    public function test_a_shopify_store_syncs_into_the_catalog_like_a_woocommerce_one(): void
    {
        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(3, $run->output['products']['created'], 'the product only on another channel is not read');
        $this->assertSame(2, $run->output['categories']['total']);
        $this->assertSame(['page', 'post'], $run->output['content']['types']);

        $this->inShop(function (): void {
            $saws = CatalogCategory::query()->where('external_id', '4001')->sole();
            $this->assertSame('מסורים', $saws->name);
            $this->assertSame('https://kli.co.il/collections/saws', $saws->url);
            $this->assertSame('https://cdn.shopify.com/saws.jpg', $saws->image_url);
            $this->assertSame(12, $saws->product_count);
            $this->assertNull($saws->parent_external_id);

            $saw = CatalogProduct::query()->where('external_id', '8001')->sole();
            $this->assertSame('מסור עגול 1400W', $saw->title);
            $this->assertSame('variable', $saw->type);
            $this->assertSame('publish', $saw->status);
            $this->assertSame('https://kli.co.il/products/circular-saw', $saw->url);
            $this->assertSame('https://cdn.shopify.com/circular-saw.jpg', $saw->image_url);
            $this->assertSame('299.00', $saw->price);
            $this->assertSame('349.00', $saw->regular_price);
            $this->assertSame('ILS', $saw->currency);
            $this->assertTrue($saw->on_sale);
            $this->assertTrue($saw->in_stock);
            $this->assertTrue($saw->purchasable);
            $this->assertSame('Makita', $saw->brand);
            $this->assertSame('SAW-210', $saw->sku, 'the first variant, the one the product page opens on');
            $this->assertSame(2, $saw->variations_count);
            $this->assertSame('circular-saw', $saw->payload['handle']);
            $this->assertSame("להב 185 מ\"מ.\nמנוע 1400W.", $saw->description());
            $this->assertSame([['name' => 'קוטר להב', 'values' => ['185', '210'], 'for_variations' => true]], $saw->storeAttributes());
            $this->assertEqualsCanonicalizing(['מסורים', 'כלי עבודה חשמליים'], $saw->categories()->get()->map->pathLabel()->all());
            $this->assertSame('2026-09-30', $saw->source_updated_at?->toDateString());

            $blade = CatalogProduct::query()->where('external_id', '8002')->sole();
            $this->assertSame('simple', $blade->type);
            $this->assertSame('89.00', $blade->price);
            $this->assertSame('89.00', $blade->regular_price);
            $this->assertFalse($blade->on_sale);
            $this->assertFalse($blade->in_stock);
            $this->assertSame(0, $blade->variations_count);
            $this->assertSame(['כלי עבודה חשמליים'], $blade->categories()->get()->map->pathLabel()->all());

            $this->assertTrue(CatalogProduct::query()->where('external_id', '8004')->exists(), 'the second page is read');
            $this->assertFalse(CatalogProduct::query()->where('external_id', '8003')->exists());

            $policy = CatalogContent::query()->where('external_id', 'policy-71')->sole();
            $this->assertSame(['page', 'מדיניות החזרות וביטולים', 'https://kli.co.il/policies/refund-policy', 'ביטול עסקה תוך 14 יום.'], [$policy->type, $policy->title, $policy->url, $policy->body], 'the store\x27s refund policy is a page the search and the assistant read');
            $this->assertFalse(CatalogContent::query()->where('external_id', 'policy-72')->exists(), 'an empty policy is not a page');

            $built = CatalogContent::query()->where('external_id', '5002')->sole();
            $this->assertStringContainsString('ניתן לבטל עסקה תוך 14 יום.', $built->body, 'a page the theme builds is read as shoppers see it');
            $this->assertStringNotContainsString('תפריט', $built->body, 'the main part only');
            $this->assertStringNotContainsString('שליחה', $built->body, 'not a form\x27s buttons');

            $page = CatalogContent::query()->where('type', 'page')->where('external_id', '5001')->sole();
            $this->assertSame('5001', $page->external_id);
            $this->assertSame('https://kli.co.il/pages/warranty', $page->url);
            $this->assertSame("אחריות לשנתיים.\nעל כל כלי.", $page->body);

            $article = CatalogContent::query()->where('type', 'post')->sole();
            $this->assertSame('6001', $article->external_id);
            $this->assertSame('איך בוחרים מסור', $article->title);
            $this->assertSame('https://kli.co.il/blogs/guides/choosing-a-saw', $article->url);
            $this->assertSame('https://cdn.shopify.com/article.jpg', $article->image_url);
            $this->assertSame(['blog' => ['מדריכים'], 'tags' => ['מסורים', 'קנייה']], $article->terms);
        });

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://kli-tools.myshopify.com/admin/api/2026-07/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'shpat_test'));
    }

    public function test_custom_fields_options_barcodes_and_weights_are_read_like_the_plugin_sends_them(): void
    {
        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);

        $this->inShop(function (): void {
            $saw = CatalogProduct::query()->where('external_id', '8001')->sole();
            $this->assertSame([
                'custom.blade_material' => 'קרביד',
                'custom.cut_depth' => '6.5 cm',
                'custom.in_the_box' => "מגיע במזוודה.\nכולל מפתח.",
                'custom.materials' => 'עץ, לבידים',
            ], $saw->payload['meta'], 'the app\x27s own fields, references, raw JSON and costs are left out');
            $this->assertSame('7290001234567', $saw->payload['gtin'], 'the barcode of the variant the page opens on');
            $this->assertSame(['3.2', 'kg'], [$saw->payload['dimensions']['weight'], $saw->payload['dimensions']['weight_unit']]);

            $variations = collect($saw->payload['variations'])->keyBy('external_id');
            $this->assertSame([['key' => 'קוטר להב', 'name' => 'קוטר להב', 'value' => '210']], $variations['9101']['attributes']);
            $this->assertSame(['185', '3000', 'g'], [$variations['9102']['attributes'][0]['value'], $variations['9102']['weight'], $variations['9102']['weight_unit']]);
            $this->assertSame('https://cdn.shopify.com/saw-185.jpg', $variations['9102']['image']);

            $blade = CatalogProduct::query()->where('external_id', '8002')->sole();
            $this->assertSame(['0.2', 'kg'], [$blade->payload['dimensions']['weight'], $blade->payload['dimensions']['weight_unit']], 'a product without options is weighed by its one variant');
            $this->assertSame([], $blade->payload['variations']);

            $drill = CatalogProduct::query()->where('external_id', '8004')->sole();
            $this->assertSame(['', ''], [$drill->payload['dimensions']['weight'], $drill->payload['gtin']], 'a weight of 0 is no weight');
        });

        Http::assertSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'query', ''), 'query ProductDetails')
            && data_get($request->data(), 'variables.ids') === ['gid://shopify/Product/8001', 'gid://shopify/Product/8002']);
    }

    public function test_a_store_whose_details_cannot_be_read_still_has_its_catalog_read(): void
    {
        $this->failDetails = true;

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(3, $run->output['products']['created']);

        $this->inShop(function (): void {
            $saw = CatalogProduct::query()->where('external_id', '8001')->sole();
            $this->assertSame([], $saw->payload['meta']);
            $this->assertSame('', $saw->payload['gtin']);
            $this->assertSame([], $saw->payload['variations'][0]['attributes']);
            $this->assertSame('299.00', $saw->price, 'everything the products page has is there');
        });
    }

    public function test_a_second_sync_of_an_unchanged_store_changes_nothing(): void
    {
        app(SyncCatalog::class)->handle($this->shop->id);

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(0, $run->output['products']['updated']);
        $this->assertSame(3, $run->output['products']['unchanged']);
        $this->assertSame(0, $run->output['categories']['updated']);
        $this->assertSame(0, $run->output['content']['updated']);
    }

    public function test_a_store_that_removed_the_app_fails_the_sync_with_the_reinstall_explanation(): void
    {
        $this->install->forceFill(['uninstalled_at' => now()])->save();

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('connections::runs.failures.not_installed', $run->summary_key);
        Http::assertNothingSent();
    }

    public function test_a_store_without_an_install_fails_the_same_way(): void
    {
        $this->install->delete();

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame('connections::runs.failures.not_installed', $run->summary_key);
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    private function respond(Request $request): mixed
    {
        $query = (string) $request['query'];
        $after = data_get($request->data(), 'variables.after');
        preg_match('/^\s*query\s+(\w+)/', $query, $match);
        $operation = $match[1] ?? '';

        if ($operation === 'ProductDetails') {
            return $this->failDetails
                ? Http::response(['errors' => [['message' => 'Access denied for inventoryItem field.', 'extensions' => ['code' => 'ACCESS_DENIED']]]], 200)
                : Http::response(['data' => ['nodes' => array_map(fn (string $id): ?array => $this->details()[$id] ?? null, (array) data_get($request->data(), 'variables.ids', []))]]);
        }

        return Http::response(['data' => match ($operation) {
            'Shop' => ['shop' => ['currencyCode' => 'ILS', 'primaryDomain' => ['url' => 'https://kli.co.il']]],
            'Collections' => ['collections' => $this->connection([
                ['id' => 'gid://shopify/Collection/4001', 'title' => 'מסורים', 'handle' => 'saws', 'description' => '', 'updatedAt' => '2026-09-01T08:00:00Z', 'image' => ['url' => 'https://cdn.shopify.com/saws.jpg'], 'productsCount' => ['count' => 12]],
                ['id' => 'gid://shopify/Collection/4002', 'title' => 'כלי עבודה חשמליים', 'handle' => 'power-tools', 'description' => '', 'updatedAt' => '2026-09-01T08:00:00Z', 'image' => null, 'productsCount' => ['count' => 40]],
            ])],
            'Products' => ['products' => $after === null
                ? $this->connection([$this->saw(), $this->sawBlade(), $this->posOnly()], 'cursor-1')
                : $this->connection([$this->drill()])],
            'Pages' => ['pages' => $this->connection([
                ['id' => 'gid://shopify/Page/5002', 'title' => 'ביטול עסקה', 'handle' => 'cancel', 'body' => '', 'bodySummary' => '', 'isPublished' => true, 'createdAt' => '2026-01-01T00:00:00Z', 'updatedAt' => '2026-02-01T00:00:00Z'],
                ['id' => 'gid://shopify/Page/5001', 'title' => 'אחריות', 'handle' => 'warranty', 'body' => '<p>אחריות לשנתיים.</p><p>על כל&nbsp;כלי.</p>', 'bodySummary' => 'אחריות לשנתיים.', 'isPublished' => true, 'createdAt' => '2026-01-01T00:00:00Z', 'updatedAt' => '2026-02-01T00:00:00Z'],
            ])],
            'Policies' => ['shop' => ['shopPolicies' => [
                ['id' => 'gid://shopify/ShopPolicy/71', 'type' => 'REFUND_POLICY', 'title' => 'מדיניות החזרות וביטולים', 'body' => '<p>ביטול עסקה תוך 14 יום.</p>', 'url' => 'https://kli.co.il/policies/refund-policy', 'updatedAt' => '2026-02-01T00:00:00Z'],
                ['id' => 'gid://shopify/ShopPolicy/72', 'type' => 'PRIVACY_POLICY', 'title' => 'מדיניות פרטיות', 'body' => '', 'url' => 'https://kli.co.il/policies/privacy-policy', 'updatedAt' => null],
            ]]],
            'Articles' => ['articles' => $this->connection([
                ['id' => 'gid://shopify/Article/6001', 'title' => 'איך בוחרים מסור', 'handle' => 'choosing-a-saw', 'body' => '<h2>קוטר</h2><p>לפי העבודה.</p>', 'summary' => '', 'isPublished' => true, 'tags' => ['קנייה', 'מסורים'], 'createdAt' => '2026-03-01T00:00:00Z', 'updatedAt' => '2026-03-02T00:00:00Z', 'image' => ['url' => 'https://cdn.shopify.com/article.jpg'], 'blog' => ['title' => 'מדריכים', 'handle' => 'guides']],
            ])],
            default => null,
        }]);
    }

    /**
     * What the details query answers per product: custom fields of every kind Shopify has, and
     * each variant's options, barcode, picture and weight.
     *
     * @return array<string, array<string, mixed>>
     */
    private function details(): array
    {
        $variant = fn (string $id, string $barcode, array $options, ?array $weight, ?string $image = null): array => [
            'id' => "gid://shopify/ProductVariant/{$id}",
            'barcode' => $barcode,
            'selectedOptions' => $options,
            'image' => $image === null ? null : ['url' => $image],
            'inventoryItem' => ['measurement' => ['weight' => $weight]],
        ];

        return [
            'gid://shopify/Product/8001' => [
                'id' => 'gid://shopify/Product/8001',
                'metafields' => ['nodes' => [
                    ['namespace' => 'custom', 'key' => 'blade_material', 'type' => 'single_line_text_field', 'value' => 'קרביד'],
                    ['namespace' => 'custom', 'key' => 'cut_depth', 'type' => 'dimension', 'value' => '{"value":6.5,"unit":"CENTIMETERS"}'],
                    ['namespace' => 'custom', 'key' => 'materials', 'type' => 'list.single_line_text_field', 'value' => '["עץ","לבידים"]'],
                    ['namespace' => 'custom', 'key' => 'in_the_box', 'type' => 'multi_line_text_field', 'value' => "מגיע במזוודה.\nכולל מפתח."],
                    ['namespace' => 'custom', 'key' => 'cost_price', 'type' => 'money', 'value' => '{"amount":"180.0","currency_code":"ILS"}'],
                    ['namespace' => 'shopify', 'key' => 'color-pattern', 'type' => 'list.metaobject_reference', 'value' => '["gid://shopify/Metaobject/1"]'],
                    ['namespace' => 'custom', 'key' => 'specs_raw', 'type' => 'json', 'value' => '{"a":1}'],
                    ['namespace' => '$app:reviews', 'key' => 'state', 'type' => 'single_line_text_field', 'value' => 'synced'],
                ]],
                'variants' => ['nodes' => [
                    $variant('9101', '7290001234567', [['name' => 'קוטר להב', 'value' => '210']], ['unit' => 'KILOGRAMS', 'value' => 3.2]),
                    $variant('9102', '7290001234568', [['name' => 'קוטר להב', 'value' => '185']], ['unit' => 'GRAMS', 'value' => 3000], 'https://cdn.shopify.com/saw-185.jpg'),
                ]],
            ],
            'gid://shopify/Product/8002' => [
                'id' => 'gid://shopify/Product/8002',
                'metafields' => ['nodes' => []],
                'variants' => ['nodes' => [$variant('9201', '7290009999999', [['name' => 'Title', 'value' => 'Default Title']], ['unit' => 'KILOGRAMS', 'value' => 0.2])]],
            ],
            'gid://shopify/Product/8004' => [
                'id' => 'gid://shopify/Product/8004',
                'metafields' => ['nodes' => []],
                'variants' => ['nodes' => [$variant('9401', '', [], ['unit' => 'GRAMS', 'value' => 0])]],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function connection(array $nodes, ?string $next = null): array
    {
        return ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $next !== null, 'endCursor' => $next]];
    }

    /** @return array<string, mixed> a product with two sizes, the cheaper one on sale */
    private function saw(): array
    {
        return $this->product('8001', 'מסור עגול 1400W', 'circular-saw', [
            'descriptionHtml' => '<p>להב 185 מ"מ.</p><p>מנוע 1400W.</p>',
            'vendor' => 'Makita',
            'productType' => 'מסורים',
            'tags' => ['מסורים', 'Makita'],
            'hasOnlyDefaultVariant' => false,
            'variantsCount' => ['count' => 2],
            'priceRangeV2' => ['minVariantPrice' => ['amount' => '299.0'], 'maxVariantPrice' => ['amount' => '359.0']],
            'options' => [['name' => 'קוטר להב', 'optionValues' => [['name' => '185'], ['name' => '210']]]],
            'collections' => ['nodes' => [
                ['id' => 'gid://shopify/Collection/4002', 'title' => 'כלי עבודה חשמליים', 'handle' => 'power-tools'],
                ['id' => 'gid://shopify/Collection/4001', 'title' => 'מסורים', 'handle' => 'saws'],
            ]],
            'variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/9101', 'title' => '210', 'sku' => 'SAW-210', 'price' => '359.00', 'compareAtPrice' => null, 'availableForSale' => false],
                ['id' => 'gid://shopify/ProductVariant/9102', 'title' => '185', 'sku' => 'SAW-185', 'price' => '299.00', 'compareAtPrice' => '349.00', 'availableForSale' => true],
            ]],
        ]);
    }

    /** @return array<string, mixed> one default variant, sold out */
    private function sawBlade(): array
    {
        return $this->product('8002', 'להב 185', 'blade-185', [
            'vendor' => 'Bosch',
            'tracksInventory' => true,
            'totalInventory' => 0,
            'priceRangeV2' => ['minVariantPrice' => ['amount' => '89.0'], 'maxVariantPrice' => ['amount' => '89.0']],
            'collections' => ['nodes' => [['id' => 'gid://shopify/Collection/4002', 'title' => 'כלי עבודה חשמליים', 'handle' => 'power-tools']]],
            'variants' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/9201', 'title' => 'Default Title', 'sku' => 'BL-185', 'price' => '89.00', 'compareAtPrice' => '89.00', 'availableForSale' => false]]],
        ]);
    }

    /** @return array<string, mixed> active, but sold only at the till: no Online Store URL */
    private function posOnly(): array
    {
        return $this->product('8003', 'שובר מתנה בחנות', 'gift-card-pos', ['onlineStoreUrl' => null]);
    }

    /** @return array<string, mixed> */
    private function drill(): array
    {
        return $this->product('8004', 'מקדחה', 'drill', [
            'priceRangeV2' => ['minVariantPrice' => ['amount' => '450.0'], 'maxVariantPrice' => ['amount' => '450.0']],
            'variants' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/9401', 'title' => 'Default Title', 'sku' => 'DR-1', 'price' => '450.00', 'compareAtPrice' => null, 'availableForSale' => true]]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function product(string $id, string $title, string $handle, array $overrides = []): array
    {
        return $overrides + [
            'id' => "gid://shopify/Product/{$id}",
            'title' => $title,
            'handle' => $handle,
            'status' => 'ACTIVE',
            'onlineStoreUrl' => "https://kli.co.il/products/{$handle}",
            'descriptionHtml' => '',
            'vendor' => '',
            'productType' => '',
            'tags' => [],
            'createdAt' => '2026-09-01T10:00:00Z',
            'updatedAt' => '2026-09-30T10:00:00Z',
            'hasOnlyDefaultVariant' => true,
            'tracksInventory' => false,
            'totalInventory' => 0,
            'variantsCount' => ['count' => 1],
            'featuredMedia' => ['preview' => ['image' => ['url' => "https://cdn.shopify.com/{$handle}.jpg", 'altText' => null]]],
            'priceRangeV2' => ['minVariantPrice' => ['amount' => '0.0'], 'maxVariantPrice' => ['amount' => '0.0']],
            'options' => [['name' => 'Title', 'optionValues' => [['name' => 'Default Title']]]],
            'collections' => ['nodes' => []],
            'variants' => ['nodes' => []],
        ];
    }
}
