<?php

namespace App\Modules\Connections\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop that moves to a new domain is read at the new address: its connection follows, unless it
 * pointed somewhere else on purpose.
 */
final class FollowShopDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_connection_follows_the_shop_to_its_new_domain(): void
    {
        $shop = Shop::factory()->create(['domain' => 'www.guetaavigdor.co.il']);
        $connection = $this->connect($shop, 'https://www.guetaavigdor.co.il');
        $siteKey = $connection->site_key;

        $shop->update(['domain' => 'shop.guetaavigdor.co.il']);

        $connection = $this->fresh($connection);
        $this->assertSame('https://shop.guetaavigdor.co.il', $connection->site_url);
        $this->assertSame($siteKey, $connection->site_key, 'the storefront keeps the same site key');
        $this->assertTrue($connection->allowsOrigin('https://shop.guetaavigdor.co.il'));
    }

    public function test_a_connection_that_points_elsewhere_on_purpose_stays(): void
    {
        $shop = Shop::factory()->create(['domain' => 'www.store.test']);
        $connection = $this->connect($shop, 'https://staging.store.test');

        $shop->update(['domain' => 'shop.store.test']);

        $this->assertSame('https://staging.store.test', $this->fresh($connection)->site_url);
    }

    private function connect(Shop $shop, string $url): StoreConnection
    {
        return app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => $url, 'access_token' => 'lat_'.str_repeat('a', 48),
        ]));
    }

    private function fresh(StoreConnection $connection): StoreConnection
    {
        return app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->findOrFail($connection->id));
    }
}
