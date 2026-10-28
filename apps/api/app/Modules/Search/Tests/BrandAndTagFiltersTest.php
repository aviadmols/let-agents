<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store of many brands that files its products by tags alone (Chozen, 28.10: "נעליים", "סניקרס", "תיקים"; THE NORTH
 * FACE, ADIDAS, TOUS) filters by them from its first scan: its brands, and its tags, never a campaign ("החדשים",
 * "NOT SALE") or whom it is for alone ("נשים/יוניסקס", which the audience filter says).
 */
final class BrandAndTagFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_store_of_many_brands_filed_by_tags_filters_by_both(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([
                ['1', 'נורתפייס נעלי בית THERMOBALL לנשים', 'THE NORTH FACE', ['החדשים', 'נעליים', 'נשים/יוניסקס', 'סניקרס']],
                ['2', 'נורתפייס מעיל פליס FLEESKI לגברים', 'THE NORTH FACE', ['החדשים', 'אופנה', "ג'קטים ומעילים"]],
                ['3', 'אדידס סניקרס SAMBA OG לגברים', 'ADIDAS', ['החדשים', 'נעליים', 'גברים/יוניסקס', 'סניקרס']],
                ['4', 'אדידס סניקרס STAN SMITH לגברים', 'ADIDAS', ['נעליים', 'גברים/יוניסקס', 'סניקרס']],
                ['5', 'טואוס תיק צד קטן Kaos Icon', 'TOUS', ['NOT SALE', 'תיקים', 'תיקי צד']],
                ['6', 'טואוס תיק יד גדול Kaos Icon', 'TOUS', ['NOT SALE', 'תיקים', 'תיקי יד']],
                ['7', 'בראוני מעיל לנשים', 'BROWNIE', ['אופנה', "ג'קטים ומעילים", 'נשים/יוניסקס']],
            ] as [$id, $title, $brand, $tags]) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'brand' => $brand,
                    'url' => "https://store.test/p/{$id}", 'price' => 300, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}",
                    'payload' => ['tags' => $tags, 'product_type' => 'חורף 26']]);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $facets = array_column((array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['facets'], null, 'k');

        $this->assertSame(['ADIDAS', 'THE NORTH FACE', 'TOUS'], array_column($facets['brand']['v'], 'l'), 'the brands of two products or more');
        $tags = array_column($facets['store_tags']['v'], 'l');
        $this->assertEqualsCanonicalizing(['נעליים', 'סניקרס', 'אופנה', "ג'קטים ומעילים", 'תיקים'], $tags);
    }
}
