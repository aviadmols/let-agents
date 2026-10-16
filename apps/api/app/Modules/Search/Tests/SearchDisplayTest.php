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
use App\Modules\Search\Actions\CountSearch;
use App\Modules\Search\Filament\Merchant\Pages\SearchDisplay;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\LoadedIndex;
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
            ->assertSet('data.price', true);

        $screen->set('data.price', false)
            ->set('data.cart', false)
            ->set('data.similar', false)
            ->set('data.per_group', 8)
            ->set('data.content_by_meaning', true)
            ->set('shop', $theirs->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Settings::get('search.show_price', $mine->id));
        $this->assertFalse(Settings::get('search.show_cart', $mine->id));
        $this->assertFalse(Features::enabled('search.similar', $mine->id));
        $this->assertSame(8, Settings::get('search.results_per_group', $mine->id));
        $this->assertTrue(Features::enabled('search.content_by_meaning', $mine->id));

        // The address cannot point the screen at another shop.
        $this->assertTrue(Settings::get('search.show_price', $theirs->id));
        $this->assertTrue(Settings::get('search.show_cart', $theirs->id));
        $this->assertFalse(Features::enabled('search.content_by_meaning', $theirs->id));
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
        Settings::set('search.show_price', false, $shop->id);

        $this->assertSame('succeeded', app(BuildSearchIndex::class)->handle($shop->id)->status->value);

        $index = $this->getJson('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index', ['Origin' => 'https://www.rings.test'])->assertOk()->json();

        $this->assertFalse($index['config']['price']);
        $this->assertTrue($index['config']['cart']);
        // Only what the drawer reads: the old search box's display modes are gone.
        foreach (['results', 'modeChoice', 'dropdown', 'suggestions', 'perGroup', 'drawerSide', 'drawerColumns', 'groups', 'hover', 'buttonColor'] as $gone) {
            $this->assertArrayNotHasKey($gone, $index['config']);
        }
        $this->assertArrayNotHasKey('labels', $index, 'the drawer carries its own words');
        $this->assertSame([], $index['config']['popular'], 'no searches yet: no popular words, never sample ones');
    }

    public function test_popular_searches_are_the_shops_own(): void
    {
        LoadedIndex::forget();
        $shop = $this->shop = Shop::factory()->create();
        $other = Shop::factory()->create();
        Features::override('search.semantic', false, $shop->id);
        Features::override('search.pictures', false, $shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://www.rings.test', 'access_token' => self::TOKEN,
        ]));
        $this->product('7', 'טבעת סוליטר');
        $this->assertSame('succeeded', app(BuildSearchIndex::class)->handle($shop->id)->status->value);

        $term = fn (string $shopId, string $query, int $searches, int $empty = 0, int $daysAgo = 1) => app(TenantContext::class)->run($shopId, fn () => SearchTerm::query()->create([
            'shop_id' => $shopId, 'day' => now()->subDays($daysAgo)->toDateString(), 'query' => $query,
            'searches' => $searches, 'empty' => $empty, 'clicks' => 0, 'last_results' => $empty < $searches ? 4 : 0,
        ]));
        $term($shop->id, 'טבעת', 9);
        $term($shop->id, 'עגילים', 3);
        $term($shop->id, 'שרשרת', 2);                 // too few
        $term($shop->id, 'יהלום ורוד', 6, 5);         // mostly found nothing
        $term($shop->id, '0521234567', 8);            // reads like a number someone typed about themselves
        $term($shop->id, 'צמיד', 7, 0, 45);           // too long ago
        $term($shop->id, CountSearch::PHOTO, 20);     // photo searches, counted under a marker
        $term($other->id, 'אימפקט', 50);              // another shop's

        $index = $this->getJson('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index', ['Origin' => 'https://www.rings.test'])->assertOk()->json();

        $this->assertSame(['טבעת', 'עגילים'], $index['config']['popular']);
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
