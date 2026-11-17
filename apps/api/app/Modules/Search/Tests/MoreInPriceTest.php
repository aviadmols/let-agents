<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Few products within a price asked: how many more a step above it holds is said, so the shopper is not left with two
 * rings when five more cost a little more (Rocks QA, 17.11). Counted by code, of what was asked alone.
 */
final class MoreInPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_round_price_a_step_above(): void
    {
        $this->assertSame([3500.0, 2500.0, 4000.0, 200.0, 90.0], array_map(SearchCatalog::nextPrice(...), [3000.0, 2000.0, 3500.0, 150.0, 80.0]));
    }

    public function test_few_within_the_price_say_how_many_more_a_step_above_it(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            // id => [title, price, in stock]
            foreach (['1' => ['טבעת זהב עדינה', 2800, true], '2' => ['טבעת זהב רחבה', 2900, true], '3' => ['טבעת זהב קלועה', 3200, true], '4' => ['טבעת זהב חלקה', 3400, true],
                '5' => ['טבעת זהב משובצת', 3450, false], '6' => ['טבעת זהב כבדה', 5000, true], '7' => ['שרשרת זהב', 3300, true]] as $id => [$title, $price, $stock]) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://b.test/p/$id", 'price' => $price, 'in_stock' => $stock, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);

        $found = app(SearchCatalog::class)->handle($shop->id, 'טבעת זהב עד 3000', ['product'], false, null, false);

        $this->assertEqualsCanonicalizing(['1', '2'], array_column($found['groups']['product'], 'external_id'));
        $this->assertSame(['max' => 3500.0, 'count' => 2], $found['price']['more'] ?? null, 'the two rings in stock up to 3,500: never the necklace, the one sold out or the one far above');

        $all = app(SearchCatalog::class)->handle($shop->id, 'טבעת זהב עד 6000', ['product'], false, null, false);
        $this->assertArrayNotHasKey('more', $all['price'] ?? [], 'nothing a step above');
    }
}
