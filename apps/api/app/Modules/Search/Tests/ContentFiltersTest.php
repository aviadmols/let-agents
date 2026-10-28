<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A content site's filters (Gil Travel, 28.10): its categories, more of them than a store's; its own tags, never one
 * its categories already offer; and how many days each tour lasts, from its title. "4 days israel max price 1000$"
 * reads the length and the price, and "israel 10 days" is a tour filed as Israel, not one whose text flies from it.
 * A store without these settings filters and searches as it did.
 */
final class ContentFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['content_locale' => 'en']);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $category = fn (string $name): CatalogCategory => CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => $name, 'name' => $name, 'path' => [$name], 'depth' => 0, 'hash' => $name, 'product_count' => 3]);
            $israel = $category('Israel Tours');
            $morocco = $category('Morocco');
            $spain = $category('Spain');
            foreach ([
                ['1', 'A 10-Day Jewish Odyssey: From the Golden City to the Red Sea', $israel, 2400, ['Israel Tours', 'Family']],
                ['2', '7 Day – Israel: The Essential Itinerary', $israel, 900, ['Israel Tours', 'Family']],
                ['3', 'Israel: Classic Tour', $israel, 3000, ['Israel Tours']],
                ['4', 'Experience 11 Days in The Imperial Cities Of Morocco', $morocco, 2000, ['Morocco', 'Desert']],
                ['5', '9 Day Tour in Morocco', $morocco, null, ['Morocco', 'Desert']],
                ['6', '4 Magical Days in Spain', $spain, 800, ['Spain', 'blog']],
                ['7', '5 Day Jewish Heritage Tour in Majorca', $spain, 950, ['Spain', 'blog']],
                ['8', 'Morocco Coast and Desert Retreat', $morocco, null, ['Morocco']],
                ['9', 'Barcelona and Gaudí', $spain, null, ['Spain']],
            ] as [$id, $title, $in, $price, $tags]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://tours.test/t/{$id}", 'price' => $price, 'in_stock' => true, 'purchasable' => false, 'hash' => "h{$id}",
                    'payload' => ['tags' => $tags, 'product_type' => 'Tours', 'description' => $id === '4' ? 'Fly in from Israel or Europe, and see the imperial cities.' : 'A tour.']]);
                $product->categories()->attach([$in->id]);
            }
        });
    }

    public function test_a_content_site_filters_by_its_categories_its_tags_and_how_many_days_a_tour_lasts(): void
    {
        $this->content();
        $facets = array_column($this->facets(), null, 'k');

        $this->assertSame(['Israel Tours', 'Morocco', 'Spain'], array_column($facets['category']['v'], 'l'));
        $this->assertSame(['Desert', 'Family'], array_column($facets['store_tags']['v'], 'l'), 'its own tags, the most used first, never one a category offers, nor "blog"');
        $this->assertSame(['2 to 5 days', '6 to 9 days', '10 to 14 days'], array_column($facets['length']['v'], 'l'), 'in the order of the days');
    }

    public function test_a_length_and_a_price_in_a_search_are_read_not_searched_for(): void
    {
        $this->content();

        $this->assertSame(['p:2'], $this->found('israel 7 days'), 'a tour of about that many days, filed as Israel');
        $this->assertSame(['p:1'], $this->found('israel 10 days'), 'the Morocco tour whose text flies from Israel is no Israel tour');
        $this->assertSame(['p:6', 'p:7'], $this->found('4 days max price 1000$'));
        $this->assertSame(['p:2'], $this->found('israel under $1000'));
        $this->assertSame([], $this->found('4 days israel max price 1000$'), 'none is all of it: none, never another');
    }

    public function test_a_store_without_them_filters_and_searches_as_it_did(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();
        $keys = array_column($this->facets(), 'k');

        $this->assertNotContains('length', $keys);
        $this->assertNotContains('store_tags', $keys);
        $this->assertContains('p:4', $this->found('11 days'), 'words, as before');
    }

    public function test_a_length_is_read_from_how_a_title_says_it(): void
    {
        $this->assertSame(10, HebrewSearch::daysIn('A 10-Day Jewish Odyssey'));
        $this->assertSame(4, HebrewSearch::daysIn('4 Magical Days of Jewish Heritage in Azerbaijan'));
        $this->assertSame(7, HebrewSearch::daysIn('6 nights in Rome'));
        $this->assertSame(5, HebrewSearch::daysIn('סיור 5 ימים בספרד'));
        $this->assertNull(HebrewSearch::daysIn('Day 3: Jerusalem'), 'a day of an itinerary is no length');
        $this->assertSame(['israel', 1000.0], [HebrewSearch::priceRange('israel max price 1000$')[0], HebrewSearch::priceRange('israel max price 1000$')[2]]);
        $this->assertSame(1500.0, HebrewSearch::priceRange('tour up to 1,500 dollars')[2]);
        $this->assertSame([null, null], array_slice(HebrewSearch::priceRange('מידה 52'), 1), 'a size is no price');
    }

    private function content(): void
    {
        foreach (['search.category_filter_max' => 40, 'search.store_tag_filter' => true, 'search.length_filter' => true] as $key => $value) {
            Settings::set($key, $value, $this->shop->id);
        }
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();
    }

    /** @return list<array<string, mixed>> */
    private function facets(): array
    {
        return (array) json_decode((string) app(TenantContext::class)->run($this->shop->id, fn () => SearchIndex::query()->sole()->items), true)['facets'];
    }

    /** @return list<string> */
    private function found(string $query): array
    {
        $ids = array_column(app(SearchCatalog::class)->handle($this->shop->id, $query, null, false, null, false)['groups']['product'] ?? [], 'id');
        sort($ids);

        return $ids;
    }
}
