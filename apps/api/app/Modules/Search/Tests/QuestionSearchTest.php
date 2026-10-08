<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
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

    public function test_a_question_shows_the_kind_it_asks_about_before_what_only_mentions_the_word(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://www.store.test', 'access_token' => 'lat_'.str_repeat('f', 48)]));
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $wood = CatalogCategory::query()->create(['shop_id' => $shop->id, 'external_id' => 'k1', 'name' => 'עץ אורן', 'path' => ['עץ אורן'], 'depth' => 0, 'hash' => 'k1', 'product_count' => 1]);
            $brackets = CatalogCategory::query()->create(['shop_id' => $shop->id, 'external_id' => 'k2', 'name' => 'תושבות', 'path' => ['תושבות'], 'depth' => 0, 'hash' => 'k2', 'product_count' => 1]);
            foreach ([['1', 'תושבת צלב לעמודי עץ לפרגולה', $brackets], ['2', 'קורה מוקצעת 7x7 אורן', $wood]] as [$id, $title, $category]) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://www.store.test/p/$id", 'price' => '20', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []])
                    ->categories()->attach($category->id);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);

        $result = app(SearchCatalog::class)->handle($shop->id, 'איזה עץ מתאים לבנית פרגולה בחוץ?', count: false, meaning: false);

        $this->assertTrue($result['subject']);
        $this->assertSame('קורה מוקצעת 7x7 אורן', $result['groups']['product'][0]['title'], 'the wood asked about comes before a bracket that mentions wood');
    }
}
