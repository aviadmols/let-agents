<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\SyncCatalog;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Support\FeedRecord;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\PluginStoreFeed;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
            str_ends_with($path, '/feed/products') => $this->page(array_values($this->products), (int) ($query['after'] ?? 0), (int) ($query['per_page'] ?? 50)),
            str_ends_with($path, '/feed/content') => Http::response(['data' => ($query['after'] ?? '0') === '0'
                ? [['external_id' => '900', 'type' => 'post', 'title' => 'איך בוחרים מסור', 'text' => 'מדריך', 'terms' => ['category' => ['בלוג']], 'hash' => 'p1']]
                : [], 'meta' => ['next_after' => null]]),
            str_ends_with($path, '/status') => Http::response(['data' => ['plugin' => ['content_post_types' => ['post']]]]),
            default => Http::response(['code' => 'rest_no_route'], 404),
        };
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
