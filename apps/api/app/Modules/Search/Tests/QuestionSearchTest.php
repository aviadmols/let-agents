<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A question typed in the box finds the products it asks about: words no product holds
 * ("ביתית", "וזולה") describe, so the search looks for what is left.
 */
final class QuestionSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_finds_the_products_it_is_about(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://www.store.test', 'access_token' => 'lat_'.str_repeat('e', 48)]));
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['בורג הברגה עדינה 32 מ"מ של קרג - 100 יח\'', 'בורג הברגה גסה 25 מ"מ של קרג - 100 יח\'', 'חוט ברזל מגולוון במבחר מידות ועוביים', 'מברגה נטענת 12V מקיטה', 'מברגה רוטטת 18V דיוולט', 'סט ביטים למברגה'] as $i => $t) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $i, 'type' => 'simple', 'status' => 'publish', 'title' => $t, 'url' => "https://www.store.test/p/$i", 'price' => (string) (10 + $i * 50), 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => "h$i", 'payload' => []]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $titles = fn (string $q): array => array_column(app(SearchCatalog::class)->handle($shop->id, $q, count: false, meaning: false)['groups']['product'], 'title');

        $found = $titles('מה הכי טוב למברגה ביתית וזולה?');
        $this->assertNotEmpty($found);
        foreach ($found as $title) {
            $this->assertStringContainsString('מברגה', $title, 'a screwdriver question finds screwdrivers, not screws');
        }
        $this->assertSame([], $titles('מה הכי טוב לאיטום גג?'), 'a question about nothing the shop sells finds nothing');
    }
}
