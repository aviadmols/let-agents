<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Settings\SettingManager;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsPopularity;
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
use Illuminate\Support\Facades\Cache;
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
            ->assertSet('data.price', true)
            ->assertSet('data.images', 'cover');

        $screen->set('data.price', false)
            ->set('data.cart', false)
            ->set('data.images', 'frame')
            ->set('data.similar', false)
            ->set('data.per_group', 8)
            ->set('data.content_by_meaning', true)
            ->set('shop', $theirs->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Settings::get('search.show_price', $mine->id));
        $this->assertFalse(Settings::get('search.show_cart', $mine->id));
        $this->assertSame('frame', Settings::get('search.image_fit', $mine->id));
        $this->assertFalse(Features::enabled('search.similar', $mine->id));
        $this->assertSame(8, Settings::get('search.results_per_group', $mine->id));
        $this->assertTrue(Features::enabled('search.content_by_meaning', $mine->id));

        // The address cannot point the screen at another shop.
        $this->assertTrue(Settings::get('search.show_price', $theirs->id));
        $this->assertTrue(Settings::get('search.show_cart', $theirs->id));
        $this->assertSame('cover', Settings::get('search.image_fit', $theirs->id));
        $this->assertFalse(Features::enabled('search.content_by_meaning', $theirs->id));
    }

    public function test_pictures_are_framed_at_gueta_and_fill_their_square_at_rocks(): void
    {
        $gueta = Shop::factory()->create(['slug' => 'gueta-avigdor']);
        $rocks = Shop::factory()->create(['slug' => 'rocks-and-gold']);
        $other = Shop::factory()->create();
        Settings::set('search.image_fit', 'frame', $rocks->id);

        (require base_path('app/Modules/Search/Database/Migrations/2026_10_17_100001_pictures_framed_at_gueta_and_filled_at_rocks.php'))->up();
        // a migration runs in its own process; here the manager's in-memory copy of the overrides is let go
        $this->app->forgetInstance(SettingManager::class);
        Settings::clearResolvedInstances();

        $this->assertSame('frame', Settings::get('search.image_fit', $gueta->id));
        $this->assertSame('cover', Settings::get('search.image_fit', $rocks->id));
        $this->assertSame('cover', Settings::get('search.image_fit', $other->id), 'every other shop keeps the default');
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
        Settings::set('search.image_fit', 'frame', $shop->id);

        $this->assertSame('succeeded', app(BuildSearchIndex::class)->handle($shop->id)->status->value);

        $index = $this->getJson('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index', ['Origin' => 'https://www.rings.test'])->assertOk()->json();

        $this->assertFalse($index['config']['price']);
        $this->assertTrue($index['config']['cart']);
        $this->assertSame('frame', $index['config']['images']);
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
        $this->product('8', 'עגילי זהב', ['payload' => ['title' => 'עגילי זהב', 'tags' => ['עגילים']]]);
        $this->product('9', 'צמיד כסף');
        $this->assertSame('succeeded', app(BuildSearchIndex::class)->handle($shop->id)->status->value);

        // A query searched this many times a day, on each of the given days back
        $term = function (string $shopId, string $query, int $perDay, array $days = [1, 2, 3, 4, 5], int $empty = 0): void {
            foreach ($days as $daysAgo) {
                app(TenantContext::class)->run($shopId, fn () => SearchTerm::query()->create([
                    'shop_id' => $shopId, 'day' => now()->subDays($daysAgo)->toDateString(), 'query' => $query,
                    'searches' => $perDay, 'empty' => $empty, 'clicks' => 0, 'last_results' => $empty < $perDay ? 4 : 0,
                ]));
            }
        };
        $site = '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index';
        $popular = fn (): array => $this->getJson($site, ['Origin' => 'https://www.rings.test'])->assertOk()->json('config.popular');

        $term($shop->id, 'טבעת', 4);                        // 20 searches on 5 days: a trend
        $term($shop->id, 'עגילי זהב', 2);                    // 10 on 5 days, whole words of its products
        $this->assertSame([], $popular(), 'too few searches in the shop to tell a trend');

        Cache::flush();
        $term($shop->id, 'א', 30);                           // a letter typed and left
        $term($shop->id, 'טבע', 10);                         // half a word
        $term($shop->id, 'סוליטר', 40, [1]);                 // many, but on one day: a moment
        $term($shop->id, 'כסף', 1);                          // a few, however steady
        $term($shop->id, 'טבעת זהב', 4, empty: 3);           // mostly found nothing
        $term($shop->id, 'שרשרת', 5);                        // the shop sells none
        $term($shop->id, 'צמיד', 7, [40, 41, 42, 43]);       // too long ago
        $term($shop->id, CountSearch::PHOTO, 20);            // photo searches, counted under a marker
        $term($other->id, 'אימפקט', 50);                     // another shop's

        $this->assertSame(['טבעת', 'עגילי זהב'], $popular());
    }

    public function test_the_empty_drawer_offers_the_products_most_added_to_the_cart_and_only_with_activity(): void
    {
        LoadedIndex::forget();
        $shop = $this->shop = Shop::factory()->create();
        Features::override('search.semantic', false, $shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://www.rings.test', 'access_token' => self::TOKEN,
        ]));
        foreach (['7' => 'טבעת סוליטר', '8' => 'עגילי זהב', '9' => 'צמיד כסף'] as $id => $title) {
            $this->product((string) $id, $title);
        }
        app(BuildSearchIndex::class)->handle($shop->id);
        $wanted = fn (): array => $this->getJson('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index', ['Origin' => 'https://www.rings.test'])->assertOk()->json('config.wanted');

        $this->assertSame([], $wanted(), 'no adds to the cart yet: no section');

        Cache::flush();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach ([['8', 9, 1, true], ['7', 4, 2, true], ['9', 0, 3, false], ['404', 20, 0, true]] as [$id, $adds, $rank, $popular]) {
                AnalyticsPopularity::query()->create(['shop_id' => $shop->id, 'product_external_id' => $id, 'adds' => $adds, 'orders' => 0, 'units' => 0, 'score' => $adds, 'rank' => $rank, 'popular' => $popular, 'window_days' => 30, 'computed_at' => now()]);
            }
        });
        $this->assertSame(['p:8', 'p:7'], $wanted(), 'the most added first; one never added, or not in the search, is not offered');
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
