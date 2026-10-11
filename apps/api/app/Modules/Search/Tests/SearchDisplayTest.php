<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Filament\Merchant\Pages\SearchDisplay;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Search\Support\ResultGroups;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * How the search shows, on the merchant's own screen: what they choose there is what the store's
 * search box is told, for their shop only.
 */
final class SearchDisplayTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_8888888888888888888888888888888888888888888888888';

    private Shop $shop;

    public function test_a_merchant_chooses_how_the_search_shows_in_their_own_shop(): void
    {
        $mine = $this->shop = Shop::factory()->create();
        $theirs = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($mine, ShopRole::Owner);
        $this->actingAs($merchant);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($mine);

        $this->product('1', 'טבעת סוליטר בכסף', ['image_url' => 'https://shop.test/a.jpg', 'price' => '1290']);

        $screen = Livewire::test(SearchDisplay::class)
            ->assertOk()
            ->assertSee('טבעת סוליטר בכסף')
            ->assertSet('data.results', 'panel')
            ->assertSet('groups.0.key', 'product');

        $screen->call('pickResults', 'drawer')
            ->set('data.drawer_side', 'end')
            ->set('data.drawer_columns', '1')
            ->set('data.price', false)
            ->set('data.cart', false)
            ->set('data.hover', true)
            ->set('data.own_color', true)
            ->set('data.color', '#C4452D')
            ->set('data.suggestions', 4)
            ->call('moveGroup', 2, -1)
            ->set('groups.2.on', false)
            ->set('shop', $theirs->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('drawer', Settings::get('search.results', $mine->id));
        $this->assertSame('end', Settings::get('search.drawer_side', $mine->id));
        $this->assertSame('1', (string) Settings::get('search.drawer_columns', $mine->id));
        $this->assertFalse(Settings::get('search.show_price', $mine->id));
        $this->assertFalse(Settings::get('search.show_cart', $mine->id));
        $this->assertTrue(Settings::get('search.hover_image', $mine->id));
        $this->assertSame('#c4452d', Settings::get('search.button_color', $mine->id));
        $this->assertSame(4, Settings::get('search.suggestions', $mine->id));
        // Guides moved above categories, and categories turned off.
        $this->assertSame('product,content', Settings::get('search.groups', $mine->id));

        // The address cannot point the screen at another shop.
        $this->assertSame('panel', Settings::get('search.results', $theirs->id));
        $this->assertTrue(Settings::get('search.show_price', $theirs->id));

        // Every group off is refused: the results need at least one.
        Livewire::test(SearchDisplay::class)
            ->set('groups.0.on', false)->set('groups.1.on', false)->set('groups.2.on', false)
            ->call('save');
        $this->assertSame('product,content', Settings::get('search.groups', $mine->id));
    }

    public function test_the_store_is_told_what_the_shop_chose(): void
    {
        LoadedIndex::forget();
        $shop = $this->shop = Shop::factory()->create();
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://www.rings.test', 'access_token' => self::TOKEN,
        ]));
        $this->product('7', 'טבעת סוליטר', [
            'image_url' => 'https://www.rings.test/front.jpg',
            'payload' => ['images' => [['url' => 'https://www.rings.test/front.jpg'], ['url' => 'https://www.rings.test/side.jpg']]],
        ]);
        Settings::set('search.groups', 'content,product', $shop->id);
        Settings::set('search.show_price', false, $shop->id);
        Settings::set('search.hover_image', true, $shop->id);
        Settings::set('search.button_color', '#c4452d', $shop->id);

        $this->assertSame('succeeded', app(BuildSearchIndex::class)->handle($shop->id)->status->value);

        $index = $this->getJson('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index', ['Origin' => 'https://www.rings.test'])->assertOk()->json();

        $this->assertSame(['content', 'product'], $index['config']['groups']);
        $this->assertFalse($index['config']['price']);
        $this->assertTrue($index['config']['cart']);
        $this->assertTrue($index['config']['hover']);
        $this->assertSame('#c4452d', $index['config']['buttonColor']);

        $ring = collect($index['items'])->firstWhere('id', 'p:7');
        $this->assertSame('https://www.rings.test/side.jpg', $ring['i2'], 'the second picture, for hover');
    }

    public function test_groups_keep_their_order_and_drop_what_is_unknown(): void
    {
        $this->assertSame(['content', 'product'], ResultGroups::parse(' content , product,content,shoes'));
        $this->assertSame(ResultGroups::ALL, ResultGroups::parse(''), 'nothing valid saved: every group');
        $this->assertSame('category,product', ResultGroups::compose(['category', 'product']));
    }

    /** @param  array<string, mixed>  $overrides */
    private function product(string $externalId, string $title, array $overrides = []): CatalogProduct
    {
        return app(TenantContext::class)->run($this->shop->id, fn (): CatalogProduct => CatalogProduct::query()->create(array_replace([
            'shop_id' => $this->shop->id, 'external_id' => $externalId, 'type' => 'simple', 'status' => 'publish',
            'title' => $title, 'price' => '499.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true,
            'hash' => md5($title), 'payload' => ['title' => $title],
        ], $overrides)));
    }
}
