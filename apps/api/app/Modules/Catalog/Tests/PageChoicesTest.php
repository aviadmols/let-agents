<?php

namespace App\Modules\Catalog\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\ReadPageChoices;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogProductChoice;
use App\Modules\Catalog\Support\PageChoices;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The choices a product page asks for that the feed does not carry (a length with a price for
 * each), read from the page's own add-to-cart form, any store's: Gueta's own theme and the plain
 * Product Add-Ons markup. Slowly, once a day, and a page that did not change is not read again.
 */
final class PageChoicesTest extends TestCase
{
    use RefreshDatabase;

    /** Gueta's theme: the length a select of an add-on, each option its share of the price and the total. */
    private const GUETA = '<form class="cart" action="/p" method="post"><div class="gueta-length"><label class="gueta-length__label" for="gueta-length-7"> אורך <abbr title="שדה חובה">*</abbr></label>'
        .'<select class="gueta-length__select" id="gueta-length-7" name="addon-7-%d7%90%d7%95%d7%a8%d7%9a-0" required><option value="">בחירת אורך</option>'
        .'<option value="3-00-%d7%9e%d7%98%d7%a8-1" data-percent="200" data-price="39.84"> 3.00 מטר </option>'
        .'<option value="4-20-%d7%9e%d7%98%d7%a8-2" data-percent="320"> 4.20 מטר </option></select></div>'
        .'<input type="number" name="quantity" value="1"><button type="submit" name="add-to-cart" value="7">הוספה לסל</button></form>';

    /** The plain Product Add-Ons markup: a select priced flat, and radio buttons priced by percent. */
    private const ADDONS = '<form class="cart variations_form"><div class="wc-pao-addon wc-pao-addon-1"><label class="wc-pao-addon-name" data-addon-name="Size">Size <em>*</em></label>'
        .'<select class="wc-pao-addon-field wc-pao-addon-select" name="addon-9-size-0" id="addon-9-size-0" required><option value="">None</option>'
        .'<option value="small-1" data-price="0" data-raw-price="0" data-price-type="flat_fee">Small</option>'
        .'<option value="large-2" data-price="15" data-raw-price="15" data-price-type="flat_fee">Large (+₪15.00)</option></select></div>'
        .'<div class="wc-pao-addon wc-pao-addon-2"><span class="wc-pao-addon-name">Finish</span>'
        .'<label><input type="radio" name="addon-9-finish-1[]" value="matte-1" data-raw-price="10" data-price-type="percentage_based"> Matte</label>'
        .'<label><input type="radio" name="addon-9-finish-1[]" value="gloss-2" data-raw-price="0" data-price-type="flat_fee"> Gloss</label></div></form>';

    public function test_a_length_and_its_price_are_read_from_gueta_s_theme(): void
    {
        $fields = PageChoices::read((string) PageChoices::form('<html><body><h1>עץ</h1>'.self::GUETA.'</body></html>'), 13.28);

        $this->assertSame([[
            'key' => 'addon-7-%d7%90%d7%95%d7%a8%d7%9a-0',
            'label' => 'אורך',
            'kind' => 'length',
            'required' => true,
            'values' => [
                ['value' => '3-00-%d7%9e%d7%98%d7%a8-1', 'label' => '3.00 מטר', 'price' => 39.84, 'meters' => 3.0],
                ['value' => '4-20-%d7%9e%d7%98%d7%a8-2', 'label' => '4.20 מטר', 'price' => 55.78, 'meters' => 4.2],
            ],
        ]], $fields, 'the total as the page wrote it, else the price and its share: 13.28 × 4.2; the name and values as the form posts them');
    }

    public function test_any_store_s_product_add_ons_are_read_the_same_way(): void
    {
        $fields = PageChoices::read((string) PageChoices::form(self::ADDONS), 100.0);

        $this->assertSame(['Size', 'Finish'], array_column($fields, 'label'));
        $this->assertSame(['size', 'choice'], array_column($fields, 'kind'));
        $this->assertSame([true, false], array_column($fields, 'required'));
        $this->assertSame([['value' => 'small-1', 'label' => 'Small', 'price' => 100.0], ['value' => 'large-2', 'label' => 'Large', 'price' => 115.0]], $fields[0]['values'], 'a flat fee added; its price out of the label');
        $this->assertSame([110.0, 100.0], array_column($fields[1]['values'], 'price'), 'a percentage of the price added');
    }

    public function test_lengths_in_metres_and_centimetres(): void
    {
        $this->assertSame(5.4, PageChoices::meters('5.40 מטר'));
        $this->assertSame(3.0, PageChoices::meters('3 m'));
        $this->assertSame(2.4, PageChoices::meters("2,4 מ'"));
        $this->assertSame(1.2, PageChoices::meters('120 ס"מ'));
        $this->assertNull(PageChoices::meters('Large'));
        $this->assertNull(PageChoices::form('<html><body>no cart here</body></html>'));
    }

    public function test_the_pages_are_read_slowly_once_a_day_and_an_unchanged_page_is_not_read_again(): void
    {
        $shop = Shop::factory()->create();
        $tenant = app(TenantContext::class);
        $tenant->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://wood.test', 'access_token' => 'rgt_'.str_repeat('b', 48)]));
        $tenant->run($shop->id, function () use ($shop): void {
            foreach (['7' => 13.28, '8' => 50.0] as $id => $price) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => "עץ {$id}",
                    'url' => "https://wood.test/p/{$id}", 'price' => $price, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
            }
        });
        $pages = ['https://wood.test/p/7' => '<html><body>'.self::GUETA.'</body></html>', 'https://wood.test/p/8' => '<html><body><form class="cart"><button name="add-to-cart" value="8">הוספה</button></form></body></html>'];
        // One fake for the whole test (fakes pile up), serving the pages as they are at each moment.
        $asked = 0;
        Http::fake(function (Request $request) use (&$pages, &$asked) {
            $asked++;

            return isset($pages[$request->url()]) ? Http::response($pages[$request->url()]) : Http::response('', 404);
        });
        $paused = [];
        $read = new ReadPageChoices(app(RecordsRuns::class), $tenant, function (int $ms) use (&$paused): void {
            $paused[] = $ms;
        });

        // Off by default: no page is read.
        $this->assertNull($read->handle($shop->id));
        Http::assertNothingSent();

        Features::override('catalog.page_choices', true, $shop->id);
        $run = $read->handle($shop->id);

        $this->assertNotNull($run);
        $this->assertSame(2, $run->output['read']);
        $this->assertSame(1, $run->output['with_choices']);
        $this->assertSame([2000], $paused, 'a pause between two pages');
        $this->assertStringContainsString('1', (string) $run->summary());
        $tenant->run($shop->id, function (): void {
            $wood = CatalogProductChoice::query()->whereHas('product', fn ($p) => $p->where('external_id', '7'))->sole();
            $this->assertTrue($wood->required());
            $this->assertEquals([3.0, 4.2], array_column($wood->fields[0]['values'], 'meters'));
            $this->assertSame([], CatalogProductChoice::query()->whereHas('product', fn ($p) => $p->where('external_id', '8'))->sole()->fields, 'read, and asks for nothing');
        });

        // The same day: nothing is due, nothing is asked.
        $asked = 0;
        $this->assertNull($read->handle($shop->id));
        $this->assertSame(0, $asked);

        // A day later the page with choices is read again; the same form is not read into fields again.
        $this->travel(21)->hours();
        $this->assertNull($read->handle($shop->id), 'the same form: no run');
        $this->assertSame(1, $asked, 'only the page with choices; the one without waits a week');

        // The product's price changed: its page is due at once, and a new price is a change.
        $tenant->run($shop->id, fn () => CatalogProduct::query()->where('external_id', '7')->update(['price' => 15, 'hash' => 'h7b']));
        $pages['https://wood.test/p/7'] = str_replace('data-price="39.84"', 'data-price="45"', $pages['https://wood.test/p/7']);
        $this->assertSame(1, $read->handle($shop->id)?->output['changed']);
        $this->assertEquals([45.0, 63.0], array_column($tenant->run($shop->id, fn () => CatalogProductChoice::query()->whereHas('product', fn ($p) => $p->where('external_id', '7'))->sole())->fields[0]['values'], 'price'));
    }

    public function test_a_woocommerce_option_is_named_by_its_attributes(): void
    {
        $this->assertSame('גוון: אלון · אורך: 3.68 מטר', CatalogProduct::variantLabel(['attributes' => [['key' => 'pa_color', 'name' => 'גוון', 'value' => 'אלון'], ['key' => 'pa_length', 'name' => 'אורך', 'value' => '3.68 מטר']]]));
        $this->assertSame('מידה 7', CatalogProduct::variantLabel(['title' => 'מידה 7', 'attributes' => [['name' => 'x', 'value' => 'y']]]), 'a title first (Shopify)');
        $this->assertSame('גוון', CatalogProduct::variantLabel(['attributes' => [['name' => 'גוון', 'value' => '']]]), 'left open: its name');
    }
}
