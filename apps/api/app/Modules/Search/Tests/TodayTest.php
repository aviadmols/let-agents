<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Livewire\TodayFeed;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Today;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Models\SearchLog;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Models\SearchWordFamily;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Today with a store's real week (the redesign, 11.2026): the money through search with the best day and what was
 * searched on it, how many found what they searched, the words searched most with the product opened most and the
 * trend, what went to the cart with the words that found it, the live searches with everything the log keeps about
 * each, a word family waiting that one tap approves, and an approved one with what it did since.
 */
final class TodayTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->setTime(10, 0));
        $this->shop = Shop::factory()->create(['name' => 'גואטה', 'currency' => 'ILS']);
        $this->merchant = User::factory()->create(['name' => 'דנה כהן']);
        $this->merchant->attachShop($this->shop);

        $this->inShop(function (): void {
            $id = $this->shop->id;
            CatalogProduct::query()->create(['shop_id' => $id, 'external_id' => '11', 'hash' => sha1('11'), 'payload' => [], 'in_stock' => true, 'purchasable' => true, 'type' => 'simple', 'status' => 'publish', 'title' => 'שמלת פשתן לבנה', 'image_url' => 'https://shop.test/dress.jpg']);
            CatalogProduct::query()->create(['shop_id' => $id, 'external_id' => '12', 'hash' => sha1('12'), 'payload' => [], 'in_stock' => true, 'purchasable' => true, 'type' => 'simple', 'status' => 'publish', 'title' => 'כובע קש', 'image_url' => 'https://shop.test/hat.jpg']);

            foreach ([['שמלה', 2, 30, 3, 0, 9], ['כובע', 3, 12, 0, 2, 4], ['שמלה', 10, 10, 0, 0, 0], ['סנדלים', 2, 5, 5, 0, 0]] as [$query, $ago, $searches, $empty, $added, $clicks]) {
                SearchTerm::query()->create(['shop_id' => $id, 'day' => now()->subDays($ago)->toDateString(), 'query' => $query, 'searches' => $searches, 'empty' => $empty, 'clicks' => $clicks, 'added' => $added, 'last_results' => 4]);
            }
            SearchClick::query()->create(['shop_id' => $id, 'day' => now()->subDays(2)->toDateString(), 'query' => 'שמלה', 'item' => 'p:11', 'title' => 'שמלת פשתן לבנה', 'clicks' => 9, 'added' => 3]);
            SearchClick::query()->create(['shop_id' => $id, 'day' => now()->subDays(3)->toDateString(), 'query' => 'כובע', 'item' => 'p:12', 'title' => 'כובע קש', 'clicks' => 4, 'added' => 2]);
            SearchLog::query()->create(['shop_id' => $id, 'query' => 'שמלה לבנה', 'results' => 4, 'clicked' => 'p:11', 'ip' => '203.0.113.9', 'device' => 'mobile', 'session_id' => 'visit-1', 'created_at' => now()->subMinutes(3)]);

            AnalyticsOrder::query()->create([
                'shop_id' => $id, 'order_ref' => hash('sha256', 'a'), 'source' => AnalyticsOrder::SOURCE_LIVE, 'total' => 480, 'currency' => 'ILS',
                'items_count' => 1, 'items' => [['product_id' => '11', 'quantity' => 1]], 'assisted' => false, 'attributed_total' => 0,
                'search_total' => 480, 'search_items' => ['11'], 'ordered_at' => now()->subDays(2),
            ]);

            // a family approved eight days ago, and one written for the store that waits
            $approved = SearchFamilyChoice::query()->create(['shop_id' => $id, 'status' => SearchFamilyChoice::APPROVED, 'words' => ['שמלה', 'שימלה'], 'auto' => false]);
            $approved->forceFill(['created_at' => now()->subDays(8)])->save();
            SearchWordFamily::query()->create(['shop_id' => $id, 'words_hash' => 'x', 'prompt_version' => 3, 'families' => [['כובע', 'כובעים']]]);
        });
    }

    public function test_today_reads_the_week_from_the_search(): void
    {
        $html = $this->actingAs($this->merchant)->get("/merchant/{$this->shop->slug}/overview")
            ->assertOk()
            ->assertDontSee('data-first-day', false)
            ->assertSee('data-hero', false)
            ->assertSee('הכנסות דרך החיפוש')
            ->assertSee('הזמנה אחת התחילה בחיפוש.')
            ->assertSee('מה הקהל שלכם רוצה השבוע')
            ->assertSee('שמלת פשתן לבנה')
            ->assertSee('מה נמכר דרך החיפוש')
            ->assertSee('תיקנתם, וזה עובד')
            ->assertSee('מחכה לכם')
            ->assertSee('כאותו דבר')
            ->getContent();

        // the words searched most: "dress" first, rising against the week before (30 now, 10 then)
        $this->assertMatchesRegularExpression('/שמלה.*כובע/s', $html);
        $this->assertStringContainsString('200%', $html);
    }

    public function test_the_parts_count_what_the_search_recorded(): void
    {
        $today = app(Today::class);
        $since = now()->subDays(7)->startOfDay();

        $this->assertSame(['searches' => 47, 'questions' => 0, 'found' => 39], $today->found($this->shop, $since, now()));

        $top = $today->top($this->shop, $since, now(), 5);
        $this->assertSame(['שמלה', 'כובע', 'סנדלים'], array_column($top, 'query'));
        $this->assertSame(['query' => 'שמלה', 'searches' => 30, 'before' => 10, 'found' => 'שמלת פשתן לבנה', 'picture' => 'https://shop.test/dress.jpg'], $top[0]);

        $sold = $today->sold($this->shop, $since, now(), 5);
        $this->assertSame(['title' => 'שמלת פשתן לבנה', 'picture' => 'https://shop.test/dress.jpg', 'adds' => 3, 'phrase' => 'שמלה', 'photo' => false], $sold[0]);

        $feed = $today->feed($this->shop);
        $this->assertSame('שמלה לבנה', $feed[0]['query']);
        $this->assertSame('שמלת פשתן לבנה', $feed[0]['got']);
        $this->assertContains(['כתובת IP', '203.0.113.9'], $feed[0]['details'], 'the owner sees the address, as asked (8.11)');
        $this->assertSame([], $today->feed($this->shop, 'נעליים'), 'the search box narrows it');

        $wins = $today->wins($this->shop);
        $this->assertSame('family:'.SearchFamilyChoice::query()->withoutGlobalScopes()->value('id'), $wins[0]->key);
        $this->assertSame(27, $wins[0]->found, 'searches of "dress" since it was approved that found something');
        $this->assertNull($wins[0]->sales, 'no visit that searched it recorded an order');
    }

    public function test_one_tap_approves_a_waiting_family_and_not_now_puts_one_off(): void
    {
        $this->actingAs($this->merchant);
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($this->shop);

        $fixes = app(Today::class)->fixes($this->shop);
        $family = collect($fixes)->first(fn ($fix): bool => str_starts_with($fix->key, 'family:'));
        $this->assertNotNull($family);
        $this->assertTrue($family->oneTap);
        $this->assertSame(['https://shop.test/hat.jpg'], $family->pictures);

        Livewire::test(ShopOverview::class)->call('later', $family->key);
        $this->assertNull(collect(app(Today::class)->fixes($this->shop))->firstWhere('key', $family->key), 'not now: gone until next week');

        $this->travel(8)->days();
        Livewire::test(ShopOverview::class)->call('approveFix', $family->key)->assertNotified();
        $this->assertTrue($this->inShop(fn (): bool => SearchFamilyChoice::query()->where('status', SearchFamilyChoice::APPROVED)->get()->contains(fn ($c): bool => in_array('כובעים', (array) $c->words, true))));
    }

    public function test_opening_a_live_search_shows_what_the_log_keeps(): void
    {
        $this->actingAs($this->merchant);
        $row = 's'.$this->inShop(fn () => SearchLog::query()->value('id'));

        Livewire::test(TodayFeed::class, ['shopId' => (string) $this->shop->id])
            ->assertSee('שמלה לבנה')
            ->assertDontSee('203.0.113.9')
            ->call('toggle', $row)
            ->assertSee('203.0.113.9')
            ->assertSee('data-feed-details', false)
            ->set('search', 'כובע')
            ->assertDontSee('שמלה לבנה');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function inShop(callable $run): mixed
    {
        return app(TenantContext::class)->run((string) $this->shop->id, $run);
    }
}
