<?php

namespace App\Modules\Shortlist\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Shortlist\Actions\DetectProductCards;
use App\Modules\Shortlist\Filament\Merchant\Pages\Compare;
use App\Modules\Shortlist\Filament\Merchant\Pages\HeartSettings;
use App\Modules\Shortlist\Filament\Merchant\Pages\Wishlist;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The heart and the wishlist, as the designer's panel lays it out: where the heart goes and what it
 * does, read by the search drawer from the shortlist config; and the theme's product card, found from
 * one of the shop's own category pages and handed to the heart script.
 */
final class HeartSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_heart_token_00000000000000000000000000000000000';

    private Shop $shop;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold', 'domain' => 'rocks-gold.example']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $this->shop->id, 'site_url' => 'https://www.rocks-gold.example', 'access_token' => self::TOKEN]));
        $this->site = SiteKeys::site(self::TOKEN);

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
    }

    public function test_the_page_saves_where_the_heart_goes_and_the_drawer_reads_it(): void
    {
        Livewire::test(HeartSettings::class)
            ->assertSee('הלב ורשימת המשאלות')
            ->assertSee('לב על כל מוצר. גולש שומר, חוזר, משתף. הרשימה היא של Lets, לא של התבנית, אז היא עובדת בכל חנות.')
            ->assertSee('ליד כפתור ההוספה לסל. נכנס לבד.')
            ->assertSee('בתוצאות החיפוש של Lets')
            ->assertSee('נשמר ונפתחת הרשימה')
            ->assertSee('זיהוי אוטומטי בדפי קטגוריה')
            ->assertSee('rocks-gold.example/product-category/…')
            ->assertSee('[let_agents_wishlist]')
            ->assertSee('הרשימות של הגולשים')
            ->assertSee(Wishlist::getUrl())
            ->assertSet('data.wishlist', false)
            ->assertSet('data.heart_click', 'quiet')
            ->set('data.wishlist', true)
            ->set('data.heart_in_results', false)
            ->set('data.heart_place', 'price')
            ->set('data.heart_click', 'open')
            ->set('data.heart_on_product', false)
            ->call('save')
            ->assertHasNoErrors();

        $shop = $this->shop->id;
        $this->assertTrue(Features::enabled('shortlist.wishlist', $shop));
        $this->assertNull(Settings::overrideFor('shortlist.heart_on_cards', $shop), 'left as the default');

        $config = $this->getJson("/api/v1/shortlist/{$this->site}/config?locale=he")->assertOk()->json();
        $this->assertSame([true, false, true, false, 'price', 'open'], [$config['wishlist'], $config['heartProduct'], $config['heartCards'], $config['heartResults'], $config['heartPlace'], $config['heartClick']]);
    }

    public function test_the_product_card_is_found_on_the_shops_own_category_page_and_turned_on(): void
    {
        Http::fake(['https://rocks-gold.example/product-category/rings/' => Http::response(self::wooCategory(), 200)]);

        $screen = Livewire::test(HeartSettings::class)
            ->set('detectUrl', 'rocks-gold.example/product-category/rings/')
            ->call('detect')
            ->assertSet('detectError', null)
            ->assertSet('detected.selector', 'li.product')
            ->assertSet('detected.count', 4)
            ->assertSee('זיהינו 4 כרטיסי מוצר בדף')
            ->assertSee('הלב יופיע בפינה העליונה של כל כרטיס')
            ->assertSee('טבעת יהלום 1')
            ->call('activate')
            ->assertSet('detected', null);

        $this->assertSame('li.product', Settings::get('shortlist.card_selector', $this->shop->id));
        Features::override('shortlist.wishlist', true, $this->shop->id);
        $this->assertSame('li.product', $this->getJson("/api/v1/shortlist/{$this->site}/config")->json('cardSelector'), 'the heart script reads it');

        // "Not this card" takes it back.
        $screen->assertSee('הזיהוי פעיל')->call('reject');
        $this->assertSame('', Settings::get('shortlist.card_selector', $this->shop->id));
    }

    public function test_only_the_shops_own_site_is_read(): void
    {
        Http::fake();

        Livewire::test(HeartSettings::class)
            ->set('detectUrl', 'https://evil.test/product-category/rings/')
            ->call('detect')
            ->assertSet('detected', null)
            ->assertSee('אפשר לזהות רק דף מהאתר של החנות');

        Http::assertNothingSent();

        Http::fake(['*' => Http::response('<html><body><p>אין כאן מוצרים</p></body></html>', 200)]);
        Livewire::test(HeartSettings::class)
            ->set('detectUrl', 'https://www.rocks-gold.example/product-category/empty/')
            ->call('detect')
            ->assertSee('לא מצאנו כרטיסי מוצר בדף הזה');
    }

    public function test_the_card_is_found_in_common_themes(): void
    {
        $detect = new DetectProductCards;

        $woo = $detect->inHtml(self::wooCategory(), 'https://rocks-gold.example/product-category/rings/', false);
        $this->assertSame(['li.product', 4], [$woo['selector'], $woo['count']], 'the menu picture in the header is not a card');
        $this->assertSame('https://rocks-gold.example/r1.jpg', $woo['sample']['image']);

        $dawn = '<html><body><main><ul id="product-grid" class="grid product-grid grid--4-col">';
        foreach ([1, 2, 3] as $i) {
            $dawn .= '<li class="grid__item scroll-trigger"><div class="card-wrapper product-card-wrapper underline-links-hover"><div class="card card--standard"><div class="card__media"><img srcset="//cdn.shopify.com/p'.$i.'.jpg 400w"></div>'
                .'<h3 class="card__heading"><a href="/collections/all/products/ring-'.$i.'">Ring '.$i.'</a></h3><div class="price"><span class="price-item">$10</span></div></div></div></li>';
        }
        $shopify = $detect->inHtml($dawn.'</ul></main></body></html>', 'https://rocks-gold.example/collections/all', true);
        $this->assertSame(['div.product-card-wrapper', 3], [$shopify['selector'], $shopify['count']]);
        $this->assertSame(['Ring 1', 'https://cdn.shopify.com/p1.jpg'], [$shopify['sample']['title'], $shopify['sample']['image']]);

        // A theme with its own addresses: priced boxes with a picture link, side by side.
        $own = '<html><body><div class="items">';
        foreach ([1, 2, 3] as $i) {
            $own .= '<div class="box"><a href="/shop/item-'.$i.'"><img src="/i'.$i.'.jpg"></a><h4>Name '.$i.'</h4><span class="cost">₪ 50</span></div>';
        }
        $generic = $detect->inHtml($own.'</div></body></html>', 'https://rocks-gold.example/c', false);
        $this->assertSame(['div.box', 3, 'Name 1'], [$generic['selector'], $generic['count'], $generic['sample']['title']]);

        $this->assertNull($detect->inHtml('<html><body><a href="/product/one/"><img src="/1.jpg"></a></body></html>', 'https://rocks-gold.example/', false), 'one product is not a grid');
    }

    public function test_the_old_wishlist_and_compare_screens_leave_the_settings_menu(): void
    {
        $this->assertFalse(Wishlist::shouldRegisterNavigation());
        $this->assertFalse(Compare::shouldRegisterNavigation());
        $this->get(Wishlist::getUrl())->assertOk();
        $this->get(Compare::getUrl())->assertOk();

        $this->assertTrue(HeartSettings::shouldRegisterNavigation());
        $this->assertSame(['shoppers', 60], [HeartSettings::$sideGroup, HeartSettings::getNavigationSort()]);
        $this->get(HeartSettings::getUrl())->assertOk();
    }

    private static function wooCategory(): string
    {
        $html = '<html><body><header><nav><a href="/product/menu-ring/"><img src="/menu.jpg"></a></nav></header><main><ul class="products columns-4">';
        foreach ([1, 2, 3, 4] as $i) {
            $html .= '<li class="product type-product post-'.$i.' status-publish '.($i === 1 ? 'first ' : '').'instock product_cat-rings has-post-thumbnail">'
                .'<a href="https://rocks-gold.example/product/ring-'.$i.'/" class="woocommerce-LoopProduct-link"><img src="/r'.$i.'.jpg"><h2 class="woocommerce-loop-product__title">טבעת יהלום '.$i.'</h2>'
                .'<span class="price"><bdi>100 ₪</bdi></span></a><a href="?add-to-cart='.$i.'" class="button add_to_cart_button">הוספה לסל</a></li>';
        }

        return $html.'</ul></main><footer><a href="/product/x/"><img src="/x.jpg"></a></footer></body></html>';
    }
}
