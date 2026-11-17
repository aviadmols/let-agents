<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "חולצה שחורה במידה", typed before the size: "במידה" asks for a filter and says no value, so it is no word a product must say.
 * Miss Nori, 17.11: one black shirt of 21, the one whose text said "במידה".
 */
final class FilterWordAloneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_word_that_only_asks_for_a_filter_is_not_searched_for(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'חולצה שחורה בייסיק', '2' => 'חולצה שחורה במידה גדולה', '3' => 'חולצה לבנה', '4' => 'כוס מידה'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://b.test/p/$id", 'price' => 80, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $ids = fn (string $q): array => array_column(app(SearchCatalog::class)->handle($shop->id, $q, ['product'], false, null, false)['groups']['product'], 'external_id');

        $this->assertEqualsCanonicalizing(['1', '2'], $ids('חולצה שחורה במידה'));
        $this->assertSame(['4'], $ids('כוס מידה'), 'the measuring cup is still found');
    }
}
