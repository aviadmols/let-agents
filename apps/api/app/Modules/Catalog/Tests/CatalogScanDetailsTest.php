<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Support\CatalogScanDetails;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The catalogue step of the scan says which product types were read and how many new ones came each day. */
final class CatalogScanDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_types_and_new_products_by_day(): void
    {
        app()->setLocale('he');
        $shop = Shop::factory()->create();

        $details = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            foreach ([['1', 'ספר'], ['2', 'ספר'], ['3', 'בגד גוף'], ['4', '']] as [$id, $type]) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "מוצר {$id}",
                    'url' => "https://store.test/{$id}", 'price' => 50, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['product_type' => $type]]);
            }

            return app(CatalogScanDetails::class)->scanDetails($shop);
        });

        [$types, $days] = $details['catalog'];
        $this->assertSame('סוגי המוצרים · 4 מוצרים פעילים', $types['title']);
        $this->assertSame([['ספר', '2 מוצרים'], ['בגד גוף', '1 מוצרים'], ['בלי סוג מוצר', '1 מוצרים']], $types['rows']);
        $this->assertSame('4 חדשים', $days['rows'][0][1], 'today');
        $this->assertCount(7, $days['rows']);
    }
}
