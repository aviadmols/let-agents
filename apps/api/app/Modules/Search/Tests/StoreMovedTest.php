<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\FollowShopDomain;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store that moved to a new domain is searched at the new one at once: the saved links move
 * and the search box is rebuilt, without waiting for the night (Gueta, from a staging address).
 */
final class StoreMovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_the_connection_moves_the_links_and_rebuilds_the_search(): void
    {
        $shop = Shop::factory()->create(['domain' => 'shop.guetaavigdor.co.il']);
        $tenant = app(TenantContext::class);
        $connection = $tenant->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://guetaavigdor.ussl.co', 'access_token' => 'lat_'.str_repeat('a', 48),
        ]));
        $product = $tenant->run($shop->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'hash' => 'h1', 'payload' => [],
            'title' => 'שולחן נגרים מקצועי',
            'url' => 'https://guetaavigdor.ussl.co/product/%d7%a9%d7%95%d7%9c%d7%97%d7%9f/',
            'image_url' => 'https://guetaavigdor.ussl.co/wp-content/uploads/table.jpg',
        ]));
        $cdn = $tenant->run($shop->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => '2', 'type' => 'simple', 'status' => 'publish', 'hash' => 'h2', 'payload' => [],
            'title' => 'מברגה', 'url' => 'https://guetaavigdor.ussl.co/product/b/',
            'image_url' => 'https://cdn.example.com/b.jpg',
        ]));

        FollowShopDomain::moveToShopDomain($tenant->runUnscoped(fn () => StoreConnection::query()->findOrFail($connection->id))->load('shop'));

        $tenant->run($shop->id, function () use ($product, $cdn): void {
            $product = $product->fresh();
            $this->assertSame('https://shop.guetaavigdor.co.il/product/%d7%a9%d7%95%d7%9c%d7%97%d7%9f/', $product->url);
            $this->assertSame('https://shop.guetaavigdor.co.il/wp-content/uploads/table.jpg', $product->image_url);
            $this->assertSame('https://cdn.example.com/b.jpg', $cdn->fresh()->image_url, 'a picture on another host stays');

            $index = json_encode(SearchIndex::query()->firstOrFail()->toArray(), JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString('shop.guetaavigdor.co.il/product/', $index, 'the search box was rebuilt now');
            $this->assertStringNotContainsString('guetaavigdor.ussl.co', $index);
        });
    }

    public function test_an_address_changed_by_hand_moves_the_links_too(): void
    {
        $shop = Shop::factory()->create(['domain' => 'shop.guetaavigdor.co.il']);
        $tenant = app(TenantContext::class);
        $connection = $tenant->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://guetaavigdor.ussl.co', 'access_token' => 'lat_'.str_repeat('b', 48),
        ]));
        $product = $tenant->run($shop->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'hash' => 'h1', 'payload' => [],
            'title' => 'מברגה', 'url' => 'https://guetaavigdor.ussl.co/product/a/', 'image_url' => 'https://guetaavigdor.ussl.co/a.jpg',
        ]));

        // The edit form saves the connection with a new address.
        $tenant->runUnscoped(fn () => StoreConnection::query()->findOrFail($connection->id)->update(['site_url' => 'https://shop.guetaavigdor.co.il/']));

        $this->assertSame('https://shop.guetaavigdor.co.il/a.jpg', $tenant->run($shop->id, fn () => $product->fresh()->image_url));
    }
}
