<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchSignal;
use App\Modules\Search\Support\PopularProducts;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a store's customers choose most: what each product sold, as the store counts it, and what its shoppers opened and
 * added to the cart from its search lately, an add worth three opens. "מה הספר הכי מומלץ" is answered by it.
 */
final class PopularProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_and_what_shoppers_open_and_add_from_search_say_what_is_popular(): void
    {
        $shop = Shop::factory()->create();
        $scores = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            foreach (['1' => 100, '2' => 10, '3' => 0, '4' => 0, '5' => 50] as $id => $sales) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => 'ספר '.$id,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'price' => 80, 'payload' => ['sales' => $sales]]);
            }
            CatalogProduct::query()->where('external_id', '5')->update(['removed_at' => now()]);
            SearchClick::query()->create(['shop_id' => $shop->id, 'day' => now()->subDays(3), 'query' => 'ספר', 'item' => 'p:3', 'title' => 'ספר 3', 'clicks' => 4, 'added' => 2]);
            SearchClick::query()->create(['shop_id' => $shop->id, 'day' => now()->subDays(200), 'query' => 'ספר', 'item' => 'p:4', 'title' => 'ספר 4', 'clicks' => 50, 'added' => 50]);
            SearchSignal::query()->create(['shop_id' => $shop->id, 'day' => now()->subDay(), 'kind' => 'add', 'query' => 'ספר', 'value' => 'p:2', 'count' => 1, 'visitors' => 1]);

            return app(PopularProducts::class)->scores($shop->id);
        });

        $this->assertSame(['1', '3', '2'], array_map('strval', array_keys($scores)), 'the best seller; then the one shoppers open and add most; an old search counts no more, a removed product never');
        $this->assertSame(0.6, $scores['1'], 'sales weigh 0.6 where the store sends them');
        $this->assertSame(['2'], array_map('strval', array_keys(app(TenantContext::class)->run($shop->id, fn (): array => app(PopularProducts::class)->scores($shop->id, ['2', '4'])))), 'among the ones asked about');
    }
}
