<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogProductChoice;
use App\Modules\Catalog\Support\PageChoices;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ring of one size whose size is on its page, not in its variants (Rocks and Gold: "מידה נוכחית: 54"):
 * the page's size is the product's size, so "מידה 54" and "במידה גדולה" find the rings that are.
 */
final class PageSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_size_a_page_says_is_read_from_its_picker_or_its_text(): void
    {
        $picker = '<variant-picker class="variant-picker" data-current-size="54" data-current-size-label="מידה נוכחית" data-decrease-label="הקטנה ל"><legend>מידה נוכחית :</legend></variant-picker>';
        $this->assertSame(['size' => 54.0, 'label' => 'מידה נוכחית'], PageChoices::size($picker));
        $this->assertSame(54.5, PageChoices::size('<div class="prose"><p>מידת הטבעת: 54.5</p></div>')['size']);
        $this->assertSame(7.0, PageChoices::size('<p>Ring size: 7</p>')['size']);
        $this->assertNull(PageChoices::size('<p>משקל: 2.5 גרם</p><p>מידה: 30 ס"מ</p>'), 'a length is not a ring size');
        $this->assertNull(PageChoices::size('<table><tr><td>מידה: 48</td></tr></table>'), 'a size table says no one size');
    }

    public function test_a_product_whose_page_asks_a_choice_opens_its_choices_not_an_add_the_store_refuses(): void
    {
        // Gueta: a beam sold in a length chosen on its page; the store refuses an add without it.
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => [['key' => 'addon-1-length-0', 'label' => 'אורך', 'kind' => 'length', 'required' => true, 'values' => [['value' => '2-1', 'label' => '2.1 מטר']]]], '2' => [], '3' => [['key' => 'gift', 'label' => 'עטיפה', 'required' => false, 'values' => []]]] as $id => $fields) {
                $beam = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => 'קורה '.$id, 'url' => "https://g.test/p/$id", 'price' => 9, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
                CatalogProductChoice::query()->create(['shop_id' => $shop->id, 'product_id' => $beam->id, 'fields' => $fields]);
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        LoadedIndex::forget();
        $buy = app(TenantContext::class)->run($shop->id, fn () => collect(LoadedIndex::for($shop->id)['records'])->mapWithKeys(fn (array $r, string $id): array => [$id => $r['buy'] ?? 0])->all());

        $this->assertSame(['p:1' => 0, 'p:2' => 1, 'p:3' => 1], array_intersect_key($buy, array_flip(['p:1', 'p:2', 'p:3'])), 'only a required choice opens the choices');
    }

    public function test_search_reads_a_page_size_as_the_products_size(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([['1', 50], ['2', 54], ['3', 58], ['4', 62], ['5', null]] as [$id, $size]) {
                $ring = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "טבעת רובי $id", 'url' => "https://r.test/p/$id", 'price' => 1000, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
                if ($size !== null) {
                    CatalogProductChoice::query()->create(['shop_id' => $shop->id, 'product_id' => $ring->id, 'fields' => [], 'size' => $size, 'size_label' => 'מידה נוכחית']);
                }
            }
        });

        app(BuildSearchIndex::class)->handle($shop->id);
        $ids = fn (string $query): array => array_column(app(SearchCatalog::class)->handle($shop->id, $query, ['product'], false, null, false)['groups']['product'], 'external_id');

        $this->assertSame(['4'], $ids('טבעת רובי במידה גדולה'), 'the largest third of the sizes there are');
        $this->assertSame(['1'], $ids('טבעת רובי במידה קטנה'));

        // The sizes the pages say are the size filter: each ring under its own, "מידה 54" chooses it.
        $index = app(TenantContext::class)->run($shop->id, fn () => LoadedIndex::for($shop->id));
        $sizes = collect($index['facets'])->firstWhere('k', BuildSearchIndex::VARIANT_SIZE);
        $this->assertSame(['50', '54', '58', '62'], array_column($sizes['v'], 'l'));
        $at = array_search($sizes, $index['facets'], true);
        $this->assertSame([$at * 1000 + 1], array_values(array_filter($index['records']['p:2']['f'] ?? [], fn (int $c): bool => intdiv($c, 1000) === $at)));
        $this->assertSame([$at * 1000 + 1], array_column(HebrewSearch::facets('טבעת רובי מידה 54', $index['facets']), 'code'));
        $this->assertSame('טבעת רובי', HebrewSearch::withoutFacetWords($index, 'טבעת רובי מידה 54'), 'searched as the filter, not as words');
    }

    public function test_a_ring_the_store_resizes_is_found_in_every_size_its_page_offers(): void
    {
        // Rocks: one variant, "מידה נוכחית: 53", and a picker that makes it 51, 52, 54 or 55
        $page = '<variant-picker data-current-size="53" data-current-size-label="מידה נוכחית" data-decrease-label="הקטנה ל" data-increase-label="הגדלה ל">'
            .'<input class="sr-only" type="radio" name="main-option1" value="51"><span class="size-label-text">הקטנה ל 51</span>'
            .'<input class="sr-only" type="radio" name="main-option1" value="52"><span class="size-label-text">הקטנה ל 52</span>'
            .'<input class="sr-only" type="radio" name="main-option1" value="54"><span class="size-label-text">הגדלה ל 54</span>'
            .'<input class="sr-only" type="radio" name="main-option1" value="55"><span class="size-label-text">הגדלה ל 55</span>'
            .'<input class="sr-only" type="radio" name="main-option1" value="53"><span>מידה נוכחית: 53</span></variant-picker>'
            .'<input type="radio" name="gift" value="1"><input type="radio" name="gift" value="2">';
        $this->assertSame([51.0, 52.0, 53.0, 54.0, 55.0], PageChoices::offered($page, 53.0));
        $this->assertSame([7.0, 7.5], PageChoices::offered('<p>Ring size: 7</p><p>Resize to 7.5 available</p>', 7.0));
        $this->assertSame([], PageChoices::offered('<p>מידה נוכחית: 53</p><table><tr><td>48</td></tr></table>', 53.0), 'one size, no other offered');

        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([['1', 53, [51, 52, 53, 54, 55]], ['2', 58, null], ['3', 60, null]] as [$id, $size, $offered]) {
                $ring = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "טבעת $id", 'url' => "https://r.test/p/$id", 'price' => 1000, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
                CatalogProductChoice::query()->create(['shop_id' => $shop->id, 'product_id' => $ring->id, 'fields' => [], 'size' => $size, 'size_label' => 'מידה נוכחית', 'sizes' => $offered]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);
        $index = app(TenantContext::class)->run($shop->id, fn () => LoadedIndex::for($shop->id));
        $sizes = collect($index['facets'])->firstWhere('k', BuildSearchIndex::VARIANT_SIZE);
        $this->assertSame(['51', '52', '53', '54', '55', '58', '60'], array_column($sizes['v'], 'l'));
        $at = array_search($sizes, $index['facets'], true);
        $this->assertCount(5, array_filter($index['records']['p:1']['f'] ?? [], fn (int $c): bool => intdiv($c, 1000) === $at), 'the ring of 53 under each of its five sizes');
        $this->assertEquals(53, $index['records']['p:1']['z'], 'its own size stays its size');
    }
}
