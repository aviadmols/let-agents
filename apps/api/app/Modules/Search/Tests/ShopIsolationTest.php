<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One shop's work sees that shop alone, wherever it was called from: a nightly loop over every
 * shop and the operator panel on "all shops" both run each shop's work inside a cross-shop block.
 */
final class ShopIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shops_work_inside_a_cross_shop_loop_sees_only_that_shop(): void
    {
        [$a, $b] = [Shop::factory()->create(), Shop::factory()->create()];
        $tenant = app(TenantContext::class);

        foreach ([$a, $b] as $shop) {
            $tenant->run($shop->id, fn () => CatalogProduct::query()->create([
                'shop_id' => $shop->id, 'external_id' => 'x-'.$shop->id, 'type' => 'simple', 'status' => 'publish', 'title' => 't', 'hash' => 'h', 'payload' => [],
            ]));
        }

        $seen = $tenant->runUnscoped(fn (): array => [
            'all' => CatalogProduct::query()->count(),
            'a' => $tenant->run($a->id, fn () => CatalogProduct::query()->pluck('shop_id')->unique()->values()->all()),
            'a_then_all' => $tenant->run($a->id, fn () => $tenant->runUnscoped(fn () => CatalogProduct::query()->count())),
            'after' => CatalogProduct::query()->count(),
        ]);

        $this->assertSame(2, $seen['all']);
        $this->assertSame([$a->id], $seen['a'], 'shop A\'s work never sees shop B, even called from a cross-shop loop');
        $this->assertSame(2, $seen['a_then_all'], 'a deliberate cross-shop block inside still crosses');
        $this->assertSame(2, $seen['after'], 'the loop is cross-shop again after the shop\'s work');
    }
}
