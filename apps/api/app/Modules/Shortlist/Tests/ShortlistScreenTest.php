<?php

namespace App\Modules\Shortlist\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Shortlist\Filament\Merchant\Pages\Compare;
use App\Modules\Shortlist\Filament\Merchant\Pages\Wishlist;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shop's own compare and wishlist screen: the store manager switches them on and places their
 * buttons, and sees what shoppers kept and compared, in their own shop only.
 */
final class ShortlistScreenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_screen_token_000000000000000000000000000000000';

    private Shop $mine;

    private Shop $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mine = Shop::factory()->create(['name' => 'החנות שלי']);
        $this->theirs = Shop::factory()->create(['name' => 'חנות אחרת']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $this->mine->id, 'site_url' => 'https://mine.test', 'access_token' => self::TOKEN]));

        foreach ([$this->mine, $this->theirs] as $shop) {
            app(TenantContext::class)->run($shop->id, function () use ($shop): void {
                foreach (['1' => 'טבעת יהלום', '2' => 'עגילי זהב', '3' => 'תליון לב'] as $id => $title) {
                    CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                        'url' => "https://store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                }
            });
        }

        $merchant = User::factory()->create();
        $merchant->attachShop($this->mine, ShopRole::Owner);
        $this->actingAs($merchant);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->mine);
    }

    public function test_the_store_manager_switches_them_on_and_sees_what_shoppers_did(): void
    {
        // Two screens of their own: compare first, then the wishlist.
        Livewire::test(Compare::class)
            ->assertSet('data.compare', false)
            ->assertDontSee('data-switch="wishlist"', false)
            ->set('data.compare', true)
            ->assertDontSee('#wishlist')
            ->call('save');

        $screen = Livewire::test(Wishlist::class)
            ->assertSet('data.wishlist', false)
            ->assertDontSee('data-switch="compare"', false)
            ->set('data.wishlist', true)
            ->assertSee('#wishlist')
            ->set('data.card_selector', '.product-card')
            ->assertSee('[let_agents_wishlist]')
            ->set('data.wishlist_place', '.header__icon--search')
            ->set('data.wishlist_floating', false)
            ->set('data.wishlist_header', true)
            ->set('data.wishlist_whatsapp', false)
            ->call('save');

        $this->assertTrue(Features::enabled('shortlist.compare', $this->mine->id));
        $this->assertTrue(Features::enabled('shortlist.wishlist', $this->mine->id));
        $this->assertSame('.product-card', Settings::get('shortlist.card_selector', $this->mine->id));
        $this->assertSame('.header__icon--search', Settings::get('shortlist.wishlist_place', $this->mine->id));
        $this->getJson('/api/v1/shortlist/'.SiteKeys::site(self::TOKEN).'/config')->assertJsonPath('wishlistPlace', '.header__icon--search')
            ->assertJsonPath('wishlistFloating', false)->assertJsonPath('wishlistHeader', true)->assertJsonPath('wishlistWhatsapp', false);
        $this->assertFalse(Features::enabled('shortlist.wishlist', $this->theirs->id), 'their own shop only');

        // Shoppers keep lists and compare.
        $site = SiteKeys::site(self::TOKEN);
        $this->postJson("/api/v1/shortlist/{$site}/wishlist", ['token' => 'browserAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'add' => ['1', '2']])->assertOk();
        $this->postJson("/api/v1/shortlist/{$site}/wishlist", ['token' => 'browserBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'add' => ['1']])->assertOk();
        $this->getJson("/api/v1/shortlist/{$site}/compare?ids=1,3")->assertOk();
        $this->getJson("/api/v1/shortlist/{$site}/compare?ids=3,2")->assertOk();
        app(TenantContext::class)->run($this->theirs->id, fn () => ShortlistWishlist::query()->create(['shop_id' => $this->theirs->id, 'token_hash' => 'x', 'share_code' => 'theirs000001', 'items' => ['3', '3']]));

        $numbers = Livewire::test(Wishlist::class)->instance()->numbers();

        $this->assertSame([2, 0, 3, 2], [$numbers['lists'], $numbers['accounts'], $numbers['saved'], $numbers['compares']]);
        $this->assertSame([['טבעת יהלום', 2], ['עגילי זהב', 1]], array_map(fn (array $p): array => [$p['title'], $p['n']], $numbers['wished']));
        $this->assertSame(['תליון לב', 2], [$numbers['compared'][0]['title'], $numbers['compared'][0]['n']]);
        Livewire::test(Wishlist::class)->assertSee('המוצרים שנשמרו הכי הרבה')->assertSee('טבעת יהלום')->assertDontSee('המוצרים שהושוו הכי הרבה');
        Livewire::test(Compare::class)->assertSee('המוצרים שהושוו הכי הרבה')->assertSee('תליון לב')->assertDontSee('המוצרים שנשמרו הכי הרבה');
    }
}
