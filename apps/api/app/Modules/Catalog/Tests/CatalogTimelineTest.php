<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The products that came into the catalog are on the operator's timeline, one line a day, named. */
final class CatalogTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_products_are_one_named_line_a_day(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['עץ אורן מוקצע 32X192', 'פלטת לביד MDF'] as $i => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $i, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => 'https://store.test/'.$i, 'price' => 38, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$i, 'payload' => []]);
            }
        });

        $this->actingAs(User::factory()->operator()->create())->withHeader('Accept-Language', 'he')
            ->get('/operator/timeline?shop='.$shop->slug.'&kind=catalog')
            ->assertOk()
            ->assertSee('2 מוצרים חדשים נכנסו לקטלוג')
            ->assertSee('עץ אורן מוקצע 32X192')
            ->assertSee('פלטת לביד MDF');
    }
}
