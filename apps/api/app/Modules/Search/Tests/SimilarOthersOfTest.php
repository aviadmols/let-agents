<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing alike, the "maybe these" say why they are there: "אולי יתאימו לכם אלה מ"אייקוני כדורים פורחים"", never "עוד 8 שאולי
 * יתאימו" beside a title of three (Take Me Home, 17.11). The name is the store's own category of the product that most of them
 * are in, never a sale, whom alone, or most of the store.
 */
final class SimilarOthersOfTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_maybe_these_are_named_by_the_category_they_share_with_it(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            $category = fn (string $id, string $name): CatalogCategory => CatalogCategory::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'name' => $name, 'path' => [$name], 'hash' => $id, 'product_count' => 0]);
            $print = $category('print', 'אייקוני כדורים פורחים');
            $sale = $category('sale', 'SALE 20%');
            $all = $category('all', 'כל המוצרים');
            $kids = $category('kids', 'ילדים');
            foreach (['1' => 'בקבוק נירוסטה כדור פורח', '2' => 'זוג בנדנות כדור פורח', '3' => 'מנשא בד כדור פורח', '4' => 'כרטיסיות יום הולדת', '5' => 'מוצץ', '6' => 'שמיכה'] as $id => $title) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'price' => 50, 'payload' => []]);
                $product->categories()->attach(array_map(fn (CatalogCategory $c): string => (string) $c->id, match ((string) $id) {
                    '1' => [$print, $sale, $all, $kids], '2', '3' => [$print, $sale, $all, $kids], '4' => [$sale, $all, $kids], default => [$all],
                }));
            }
        });

        $search = app(SearchCatalog::class);
        $this->assertSame('אייקוני כדורים פורחים', $search->othersOf($shop->id, '1', ['2', '3', '4']), 'two of three are of its print; a sale, all and whom name nothing');
        $this->assertNull($search->othersOf($shop->id, '1', ['4', '5', '6']), 'none of its own named categories holds half of them');
        $this->assertNull($search->othersOf($shop->id, '1', []));
    }
}
