<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
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

    /**
     * A Shopify store filters by the brands its products' own words say (Aviad, 20.11: brands are an important detail, never
     * from the Vendor field, mostly the store's own details): read by code, a Vendor kept only where its product says it.
     */
    public function test_a_shopify_store_filters_by_brands_its_products_say_never_by_its_vendor_alone(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['platform' => 'shopify']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([
                ['1', 'עגלת BUGABOO Fox', 'Bugaboo', 'store_field_said'], ['2', 'עגלת BUGABOO Dragonfly', 'Bugaboo', 'store_field_said'],
                ['3', 'מושב בטיחות Britax Dualfix', 'Britax', 'store_field_said'], ['4', 'מושב בטיחות Britax Swingfix', 'Britax', 'store_field_said'],
                // a consignor's name, read before its product had to say it: never a brand
                ['5', 'טבעת וינטג׳', 'Aeraj', 'store_field'], ['6', 'צמיד וינטג׳', 'Aeraj', 'store_field'],
            ] as [$id, $title, $brand, $source]) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'brand' => $brand,
                    'url' => "https://store.test/p/{$id}", 'price' => 300, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['vendor' => $brand]]);
                EnrichmentFact::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'kind' => FactKind::Brand, 'key' => 'brand', 'value_text' => $brand,
                    'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'status_reason' => 'code_'.$source, 'input_hash' => 'h'.$id, 'model' => 'code']);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $facets = array_column((array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['facets'], null, 'k');

        $this->assertSame(['Britax', 'Bugaboo'], array_column($facets['brand']['v'], 'l'), 'the brands its products say; never the Vendor alone');
    }

    public function test_a_tag_written_for_the_stores_systems_is_no_filter(): void
    {
        // Shilav, 2.11: "low-stock-flagged", "shilav_p" and "NewIn" among the shopper's filters
        LoadedIndex::forget();
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1', '2', '3', '4', '5'] as $id) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "מוצר {$id}",
                    'url' => "https://store.test/p/{$id}", 'price' => 300, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}",
                    'payload' => ['tags' => $id < '3' ? ['low-stock-flagged', 'shilav_p', 'NewIn', 'SHILAV_B', 'first_step_shoes', 'bis-hidden', 'SETHIT', 'nov_ROOM', 'איסוף עצמי', 'צעצועים_ומשחקים', 'נעלי צעד ראשון', 'T-Shirt', 'Cotton Blend'] : ['שאר המוצרים']]]);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $facets = array_column((array) json_decode((string) app(TenantContext::class)->run($shop->id, fn () => SearchIndex::query()->sole()->items), true)['facets'], null, 'k');

        // ...nor how it is sold ("איסוף עצמי") nor a code of Hebrew words joined by "_" (Shilav, 19.11: "the store's internal tags")
        $this->assertEqualsCanonicalizing(['נעלי צעד ראשון', 'שאר המוצרים'], array_column($facets['store_tags']['v'], 'l'), 'a Hebrew store: its Hebrew tags');
    }
}
