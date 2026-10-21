<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Read lately" on the shop's home: what came in or changed, the newest first, each opening on the scan screen. */
final class RecentlyReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_lists_what_came_in_or_changed_lately_each_opening_on_the_scan_screen(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $book = app(TenantContext::class)->run($shop->id, function () use ($shop): CatalogProduct {
            CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'ספר ישן', 'url' => 'https://s.test/p/1', 'price' => 50, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'payload' => []]);
            $this->travel(5)->minutes();

            return CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '2', 'type' => 'simple', 'status' => 'publish', 'title' => 'ספר חדש שנכנס', 'url' => 'https://s.test/p/2', 'price' => 50, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h2', 'payload' => []]);
        });

        $this->actingAs($merchant)->withHeader('Accept-Language', 'he')->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee('נסרקו לאחרונה')
            ->assertSeeInOrder(['ספר חדש שנכנס', 'ספר ישן'])
            ->assertSee('scan-summary?tab=products&amp;open='.$book->id, false);
    }
}
