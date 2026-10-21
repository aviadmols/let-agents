<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\SyncCatalog;
use App\Modules\Catalog\Actions\SyncChangedProducts;
use App\Modules\Catalog\Actions\SyncStock;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Catalog\Support\FeedRecord;
use App\Modules\Connections\Contracts\ReadsStock;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\PluginStoreFeed;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Tenancy\Models\Shop;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SyncCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var array<string, array<string, mixed>> external id => product record the fake store serves */
    private array $products = [];

    private int $failuresLeft = 0;

    private bool $rejectToken = false;

    /** @var list<array<string, mixed>> the queries the products were asked with */
    private array $asked = [];

    private int $contentReads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // No real waiting between retries in tests.
        $this->app->bind(StoreFeed::class, fn () => new PluginStoreFeed(fn (int $seconds) => null));

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id,
            'site_url' => 'https://store.test',
            'access_token' => 'rgt_'.str_repeat('a', 48),
        ]));

        foreach (['11', '12', '13'] as $id) {
            $this->products[$id] = $this->record($id);
        }

        Http::fake(fn (Request $request) => $this->respond($request));
    }

    public function test_a_first_sync_reads_categories_products_and_articles_and_links_them(): void
    {
        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(3, $run->output['products']['created']);
        $this->assertStringContainsString('3', (string) $run->summary());

        $this->inShop(function (): void {
            $this->assertSame(2, CatalogCategory::query()->count());
            $product = CatalogProduct::query()->where('external_id', '12')->sole();
            $this->assertEqualsCanonicalizing(['כלי עבודה חשמליים', 'כלי עבודה חשמליים › מסורים'], $product->categories()->get()->map->pathLabel()->all());
            $this->assertSame('299.00', $product->price);
            $this->assertTrue($product->in_stock);
            $this->assertSame('Makita', $product->brand);
            $this->assertSame(1, CatalogContent::query()->count());
        });
    }

    public function test_between_nights_the_products_alone_are_refreshed_and_only_a_change_is_written(): void
    {
        app(SyncCatalog::class)->handle($this->shop->id);
        $reads = $this->contentReads;

        $this->assertNull(app(SyncCatalog::class)->refresh($this->shop->id), 'nothing changed: no run, no rebuild');

        // A sale moved the price, the stock system emptied one, and one left the store.
        $this->products['12']['price']['price'] = '249';
        $this->products['11']['stock'] = ['in_stock' => false, 'status' => 'outofstock'];
        unset($this->products['13']);
        Event::fake([CatalogProductsChanged::class]);

        $run = app(SyncCatalog::class)->refresh($this->shop->id);

        $this->assertSame(SyncCatalog::REFRESH, $run->action);
        $this->assertSame(1, $run->output['products']['removed']);
        $this->assertSame(['11', '12'], $run->output['changed']);
        $this->assertSame($reads, $this->contentReads, 'no pages or posts read between nights');
        Event::assertDispatched(CatalogProductsChanged::class);
        $this->inShop(function (): void {
            $this->assertSame('249.00', CatalogProduct::query()->where('external_id', '12')->value('price'));
            $this->assertFalse((bool) CatalogProduct::query()->where('external_id', '11')->value('in_stock'));
            $this->assertNotNull(CatalogProduct::query()->where('external_id', '13')->value('removed_at'));
        });
    }

    public function test_a_woocommerce_store_says_what_changed_within_minutes(): void
    {
        app(SyncCatalog::class)->handle($this->shop->id);
        $this->products['12']['price']['price'] = '199';
        $this->products['13']['status'] = 'draft';
        $this->asked = [];

        $run = app(SyncChangedProducts::class)->handle($this->shop->id);

        $this->assertNotNull($run, 'the plugin can say what changed: no wait for the night');
        $this->assertSame('any', $this->asked[0]['status'], 'every status, so one taken off the store is seen');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $this->asked[0]['since']);
        $this->inShop(function (): void {
            $this->assertSame('199.00', CatalogProduct::query()->where('external_id', '12')->value('price'));
            $this->assertNotNull(CatalogProduct::query()->where('external_id', '13')->value('removed_at'), 'made a draft: off the catalogue');
        });
    }

    public function test_costs_and_internal_notes_are_never_stored_even_from_an_old_plugin(): void
    {
        app(SyncCatalog::class)->handle($this->shop->id);

        $payload = $this->inShop(fn () => CatalogProduct::query()->where('external_id', '11')->sole()->payload);

        $this->assertSame(['product_feature_0_title' => 'הספק'], $payload['meta']);
        $this->assertStringNotContainsString('4321.87', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_a_second_sync_skips_unchanged_products_even_when_the_store_shuffles_categories(): void
    {
        app(SyncCatalog::class)->handle($this->shop->id);

        $this->products['11']['categories'] = array_reverse($this->products['11']['categories']);
        $this->products['11']['tags'] = array_reverse($this->products['11']['tags']);
        $this->products['12']['price']['price'] = '279';

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(0, $run->output['products']['created']);
        $this->assertSame(1, $run->output['products']['updated'], 'only the price change counts');
        $this->assertSame(2, $run->output['products']['unchanged']);
    }

    public function test_a_product_the_store_stopped_publishing_is_marked_removed_not_deleted(): void
    {
        app(SyncCatalog::class)->handle($this->shop->id);
        unset($this->products['13']);

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(1, $run->output['products']['removed']);
        $this->assertNotNull($this->inShop(fn () => CatalogProduct::query()->where('external_id', '13')->sole()->removed_at));
    }

    public function test_a_sync_that_hits_the_product_limit_marks_nothing_removed(): void
    {
        app(SyncCatalog::class)->handle($this->shop->id);
        Settings::set('catalog.max_products', 100, $this->shop->id);
        foreach (range(100, 205) as $id) {
            $this->products[(string) $id] = $this->record((string) $id);
        }
        unset($this->products['13']);

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertTrue($run->output['products']['capped']);
        $this->assertSame(0, $run->output['products']['removed']);
        $this->assertSame('catalog::runs.synced_capped', $run->summary_key);
    }

    public function test_a_temporary_server_error_is_retried(): void
    {
        $this->failuresLeft = 2;

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Succeeded, $run->status);
    }

    public function test_a_rejected_token_fails_the_run_with_the_connection_explanation(): void
    {
        $this->rejectToken = true;

        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('connections::runs.failures.invalid_token', $run->summary_key);
    }

    public function test_the_stored_hash_ignores_the_order_of_lists_that_carry_no_meaning(): void
    {
        $record = $this->record('11');
        $shuffled = $record;
        $shuffled['categories'] = array_reverse($record['categories']);
        $shuffled['relations'] = array_reverse($record['relations']);

        $this->assertSame(FeedRecord::product($record)['hash'], FeedRecord::product($shuffled)['hash']);
    }

    public function test_stock_alone_is_read_lightly_and_only_what_changed_is_written(): void
    {
        app(SyncCatalog::class)->handle($this->shop->id);
        $this->inShop(fn () => CatalogVariant::query()->create(['shop_id' => $this->shop->id, 'product_id' => CatalogProduct::query()->where('external_id', '12')->value('id'), 'external_id' => '121', 'position' => 0, 'title' => 'S', 'in_stock' => true]));
        $stock = [['11' => ['in_stock' => false, 'variants' => []], '12' => ['in_stock' => true, 'variants' => ['121' => false]], '13' => ['in_stock' => true, 'variants' => []]]];
        $feed = new class($stock) implements ReadsStock, StoreFeed
        {
            public int $reads = 0;

            public function __construct(private readonly array $pages) {}

            public function stock(StoreConnection $connection): ?Generator
            {
                $this->reads++;
                yield from $this->pages;
            }

            public function categories(StoreConnection $connection): array
            {
                return [];
            }

            public function products(StoreConnection $connection): Generator
            {
                yield from [];
            }

            public function content(StoreConnection $connection, string $type): Generator
            {
                yield from [];
            }

            public function contentTypes(StoreConnection $connection): array
            {
                return [];
            }
        };
        $this->app->instance(StoreFeed::class, $feed);
        Event::fake([CatalogProductsChanged::class]);

        $run = app(SyncStock::class)->handle($this->shop->id);

        $this->assertSame(['products' => 1, 'variants' => 1, 'read' => 3], array_intersect_key($run->output, ['products' => 1, 'variants' => 1, 'read' => 1]));
        $this->inShop(function (): void {
            $this->assertFalse(CatalogProduct::query()->where('external_id', '11')->sole()->in_stock, 'sold out');
            $this->assertFalse(CatalogVariant::query()->where('external_id', '121')->sole()->in_stock, 'its size sold out');
        });
        Event::assertDispatched(CatalogProductsChanged::class, fn (CatalogProductsChanged $e): bool => $e->externalIds === ['11', '12']);

        $this->assertNull(app(SyncStock::class)->handle($this->shop->id), 'checked a moment ago: not due');
        $this->assertSame(1, $feed->reads);
        $this->assertNull(app(SyncStock::class)->handle($this->shop->id, now: true), 'read again, nothing changed: no run');
    }

    public function test_a_product_with_variants_has_a_row_for_each_and_its_stock_follows_the_store(): void
    {
        $variant = fn (string $id, string $color, string $size, bool $inStock): array => [
            'external_id' => $id,
            'status' => 'publish',
            'attributes' => [['key' => 'pa_color', 'name' => 'צבע', 'value' => $color], ['key' => 'pa_size', 'name' => 'מידה', 'value' => $size]],
            'price' => ['price' => '99', 'regular_price' => '99', 'on_sale' => false],
            'stock' => ['in_stock' => $inStock, 'managed' => false, 'quantity' => null],
            'purchasable' => $inStock,
            'image' => '',
        ];
        $this->products['14'] = ['type' => 'variable', 'title' => 'חולצת טריקו', 'attributes' => [
            ['key' => 'pa_color', 'name' => 'צבע', 'values' => ['שחור', 'נייבי'], 'used_for_variations' => true],
            ['key' => 'pa_size', 'name' => 'מידה', 'values' => ['S', 'M'], 'used_for_variations' => true],
        ], 'variations' => [
            $variant('141', 'שחור', 'S', true),
            $variant('142', 'שחור', 'M', true),
            $variant('143', 'נייבי', 'S', false),
            $variant('144', 'נייבי', 'M', true),
        ]] + $this->record('14');

        app(SyncCatalog::class)->handle($this->shop->id);

        $this->inShop(function (): void {
            $shirt = CatalogProduct::query()->where('external_id', '14')->sole();
            $this->assertSame(['141', '142', '143', '144'], $shirt->variants->pluck('external_id')->all());
            $navyS = $shirt->variants->firstWhere('external_id', '143');
            $this->assertSame([['blue'], 'S', false], [$navyS->color_families, $navyS->size_key, $navyS->in_stock]);
            $this->assertSame(0, CatalogProduct::query()->where('external_id', '11')->sole()->variants()->count(), 'a product without variants has no rows');
        });

        // Navy in S is back in stock, black in M sold out: the rows follow.
        $this->products['14']['variations'][2]['stock']['in_stock'] = true;
        $this->products['14']['variations'][1]['stock']['in_stock'] = false;
        app(SyncCatalog::class)->refresh($this->shop->id);

        $this->inShop(function (): void {
            $stock = CatalogProduct::query()->where('external_id', '14')->sole()->variants->pluck('in_stock', 'external_id')->all();
            $this->assertSame(['141' => true, '142' => false, '143' => true, '144' => true], $stock);
        });

        // Rows lost, or never built for a product unchanged since before they existed: the nightly read builds them.
        $this->inShop(fn () => CatalogVariant::query()->delete());
        $run = app(SyncCatalog::class)->handle($this->shop->id);

        $this->assertSame(1, $run->output['products']['variants_built']);
        $this->inShop(fn () => $this->assertSame(4, CatalogVariant::query()->count()));
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    /** @return array<string, mixed> */
    private function record(string $id): array
    {
        return [
            'external_id' => $id,
            'type' => 'simple',
            'status' => 'publish',
            'title' => "מסור {$id}",
            'url' => "https://store.test/product/{$id}",
            'sku' => "SKU-{$id}",
            'short_description' => '',
            'description' => 'מסור עגול 1400W',
            'images' => [['url' => "https://store.test/{$id}.jpg", 'alt' => '']],
            'categories' => [
                ['id' => '1751', 'name' => 'כלי עבודה חשמליים', 'path' => ['כלי עבודה חשמליים']],
                ['id' => '2360', 'name' => 'מסורים', 'path' => ['כלי עבודה חשמליים', 'מסורים']],
            ],
            'tags' => ['מסורים', 'Makita'],
            'brands' => ['Makita'],
            'attributes' => [],
            'meta' => ['product_feature_0_title' => 'הספק', 'עלות ליחידה מהספק (לא לפרסום)' => '4321.87', 'הערת קליטה' => 'x'],
            'relations' => [['type' => 'upsell', 'target' => '12', 'source' => 'merchant'], ['type' => 'cross_sell', 'target' => '13', 'source' => 'merchant']],
            'price' => ['currency' => 'ILS', 'price' => '299', 'regular_price' => '349', 'on_sale' => true],
            'stock' => ['in_stock' => true, 'status' => 'instock'],
            'purchasable' => true,
            'variations' => [],
            'updated_at' => '2026-09-16T10:00:00+00:00',
            'hash' => 'plugin-hash-'.$id,
        ];
    }

    private function respond(Request $request): mixed
    {
        if ($this->rejectToken) {
            return Http::response(['code' => 'let_agents_invalid_token'], 401);
        }

        if ($this->failuresLeft > 0) {
            $this->failuresLeft--;

            return Http::response('<html>Bad gateway</html>', 502);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/feed/categories') => Http::response(['data' => [
                ['external_id' => '1751', 'name' => 'כלי עבודה חשמליים', 'parent_id' => null, 'path' => ['כלי עבודה חשמליים'], 'hash' => 'c1'],
                ['external_id' => '2360', 'name' => 'מסורים', 'parent_id' => '1751', 'path' => ['כלי עבודה חשמליים', 'מסורים'], 'hash' => 'c2'],
            ]]),
            str_ends_with($path, '/feed/products') => $this->products($query),
            str_ends_with($path, '/feed/content') && ++$this->contentReads > 0 => Http::response(['data' => ($query['after'] ?? '0') === '0'
                ? [['external_id' => '900', 'type' => 'post', 'title' => 'איך בוחרים מסור', 'text' => 'מדריך', 'terms' => ['category' => ['בלוג']], 'hash' => 'p1']]
                : [], 'meta' => ['next_after' => null]]),
            str_ends_with($path, '/status') => Http::response(['data' => ['plugin' => ['content_post_types' => ['post']]]]),
            default => Http::response(['code' => 'rest_no_route'], 404),
        };
    }

    /** The products as the plugin serves them: published ones, or every status when asked for "any". @param array<string, mixed> $query */
    private function products(array $query): mixed
    {
        $this->asked[] = $query;
        $any = ($query['status'] ?? 'publish') === 'any';

        return $this->page(array_values(array_filter($this->products, fn (array $r): bool => $any || $r['status'] === 'publish')), (int) ($query['after'] ?? 0), (int) ($query['per_page'] ?? 50));
    }

    /** @param list<array<string, mixed>> $records */
    private function page(array $records, int $after, int $perPage): mixed
    {
        usort($records, fn (array $a, array $b): int => (int) $a['external_id'] <=> (int) $b['external_id']);
        $remaining = array_values(array_filter($records, fn (array $r): bool => (int) $r['external_id'] > $after));
        $page = array_slice($remaining, 0, $perPage);
        $next = count($remaining) > $perPage ? (int) end($page)['external_id'] : null;

        return Http::response(['data' => $page, 'meta' => ['next_after' => $next]]);
    }
}
