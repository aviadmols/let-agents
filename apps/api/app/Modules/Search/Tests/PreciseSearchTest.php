<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A query of several words means all of them: "טבעת יהלום סוליטר בכסף" is a silver diamond
 * solitaire, not every diamond ring and every silver ring. Only when nothing holds every word
 * does the search widen to what holds most of them.
 */
final class PreciseSearchTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach ([
                '1' => 'טבעת סוליטר יהלום כסף 925',
                '2' => 'טבעת סוליטר יהלום זהב 14 קראט',
                '3' => 'טבעת כסף סוליטר זירקון',
                '4' => 'טבעת יהלומים זהב לבן',
                '5' => 'שרשרת כסף עם תליון יהלום',
            ] as $id => $title) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [],
                    'price' => ['1' => 900, '2' => 2400, '3' => 300, '4' => 3900, '5' => 1200][$id],
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_every_word_counts_and_what_holds_them_all_is_shown_alone(): void
    {
        $this->assertSame(['1'], $this->found('טבעת יהלום סוליטר בכסף'), 'the silver diamond solitaire, not the gold one and not the silver zircon');
        $this->assertSame(['1'], $this->found('טבעת סוליטר יהלום כסף'), 'in any order');
        $this->assertEqualsCanonicalizing(['1', '2', '3'], $this->found('טבעת סוליטר'), 'every solitaire');
        $this->assertEqualsCanonicalizing(['1', '3'], $this->found('טבעת כסף תכשיט'), 'a word the shop never uses does not empty a longer search');
    }

    public function test_only_when_nothing_holds_every_word_does_the_search_widen(): void
    {
        $this->assertEqualsCanonicalizing(['1', '2'], $this->found('טבעת יהלום סוליטר פלטינה'), 'no platinum: the diamond solitaires, whatever their metal');
    }

    public function test_the_engine_says_which_hits_hold_the_whole_query(): void
    {
        $engine = app(TenantContext::class)->run($this->shop->id, fn (): array => LoadedIndex::for($this->shop->id)['engine']);
        $hits = HebrewSearch::search($engine, 'טבעת יהלום סוליטר בכסף');
        $full = array_column(array_filter($hits, fn (array $h): bool => $h['full']), 'id');

        $this->assertSame(['p:1'], $full);
        $this->assertSame('p:1', $hits[0]['id'], 'what holds the whole query comes first');
        $this->assertContains('p:2', array_column($hits, 'id'), 'what holds most of it is still a hit');
    }

    /** @return list<string> product external ids, in order */
    private function found(string $query): array
    {
        return array_column(app(SearchCatalog::class)->handle($this->shop->id, $query, count: false)['groups']['product'], 'external_id');
    }

    public function test_a_word_after_without_is_kept_out(): void
    {
        $this->assertEqualsCanonicalizing(['3'], $this->found('טבעת סוליטר בלי יהלומים'), 'the solitaires, not the ones with diamonds');
        $this->assertEqualsCanonicalizing(['1', '3'], $this->found('טבעת כסף ללא זהב'));
        $this->assertSame(['טבעת סוליטר', ['יהלומ']], HebrewSearch::without('טבעת סוליטר בלי יהלומים'));
    }

    public function test_a_price_in_the_words_is_a_range_not_a_word(): void
    {
        $this->assertEqualsCanonicalizing(['2'], $this->found('טבעת יהלום בזהב עד 2500 שח'), 'the gold diamond ring at 2,400, not the white gold one at 3,900');
        $this->assertEqualsCanonicalizing(['2', '4'], $this->found('טבעת יהלום זהב'), 'without the price, both');
        $this->assertEqualsCanonicalizing(['4'], $this->found('טבעת מעל 3 אלף ש״ח'));
        $this->assertEqualsCanonicalizing(['1', '5'], $this->found('יהלום בין 800 ל-1500'));

        $this->assertSame(['טבעת יהלום בזהב', null, 2500.0], HebrewSearch::priceRange('טבעת יהלום בזהב עד 2500 שח'));
        $this->assertSame(['טבעת', 1000.0, 2000.0], HebrewSearch::priceRange('טבעת מ-1000 עד 2,000 ש"ח'));
        $this->assertSame(['טבעת סוליטר יהלום', null, 1500.0], HebrewSearch::priceRange('טבעת סוליטר יהלום עד 1500.'), 'a full stop after the amount');
        $this->assertSame(['טבעת', null, 1500.0], HebrewSearch::priceRange('טבעת עד 1,500 ש"ח?'));
        $this->assertSame(['טבעת זהב לבן', null, 2500.0], HebrewSearch::priceRange('טבעת עד 2.5 אלף, זהב לבן'));
        $this->assertSame(['טבעת יהלום', 2000.0, 2500.0], HebrewSearch::priceRange('טבעת יהלום במחיר שבין 2000 עד 2500'), 'a prefix before "בין", and the word that introduced the price');
        $this->assertSame(['שרשרת', 300.0, 600.0], HebrewSearch::priceRange('שרשרת בתקציב של 300 עד 600 ₪'));
        $this->assertSame(['טבעת 14-18K', null, null], HebrewSearch::priceRange('טבעת 14-18K'), 'a karat range is not a price');
        $this->assertSame(['מדף 60 ס"מ', null, null], HebrewSearch::priceRange('מדף 60 ס"מ'), 'a size is not a price');
    }
}
