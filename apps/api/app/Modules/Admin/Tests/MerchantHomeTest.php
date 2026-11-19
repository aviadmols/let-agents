<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Livewire\TodayFeed;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Today, the shop owner's home, as the designer's redesign lays it out (11.2026). A store no shopper has searched yet
 * sees its first day: what is done, what Let’s already knows, the live searches waiting for the first shopper, and the
 * weekly email. The period is 7 days by default, and 30, 90 or dates. The panel opens in English; Hebrew is the
 * person's choice. Nothing about models or money spent on them. (A store with searches: Search's TodayTest.)
 */
final class MerchantHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_store_with_no_search_yet_sees_its_first_day(): void
    {
        $shop = Shop::factory()->create(['name' => 'ספרים ובגדים']);
        $merchant = User::factory()->create(['name' => 'דנה כהן']);
        $merchant->attachShop($shop);

        $html = $this->actingAs($merchant)
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee(', דנה.')
            ->assertSee('החיפוש באוויר.')
            ->assertSee('data-first-day', false)
            ->assertSee('היום הראשון')
            ->assertSee('החיפוש החכם פועל באתר שלכם.')
            ->assertSee('מה Let’s כבר יודע על החנות שלכם')
            ->assertSee('עוד אין מה לתקן')
            ->assertSee('מחכים לגולש הראשון')
            ->assertSee('data-live-feed', false)
            ->assertSee('data-weekly-mail', false)
            ->assertDontSee('data-hero', false)
            ->getContent();

        preg_match('/<div class="lp-page lp-d" data-home.*/s', $html, $page);
        $this->assertStringNotContainsString('$', strip_tags($page[0]), 'no dollar on the shop owner\'s home');

        // the sidebar: the store, whether search is live, the language switch
        $this->assertStringContainsString('class="lp-store"', $html);
        $this->assertStringContainsString(route('admin.locale', ['locale' => 'en']), $html);

        $this->get("/merchant/{$shop->slug}/notices")->assertOk()->assertSee('הודעות ותובנות');
        $this->get("/merchant/{$shop->slug}/connection")->assertRedirect("/merchant/{$shop->slug}/settings/connection");
        // the user menu: the connection and the plan, each to its own page (the plan left the connection page in the redesign)
        $this->get("/merchant/{$shop->slug}/settings/connection")->assertOk()->assertSee('החיבור לחנות')
            ->assertSee('חבילה וחיוב')->assertSee("/merchant/{$shop->slug}/settings/plan", false);
    }

    /**
     * No shopper searched yet, and something already waits for the owner: Today never calls it a steady week nor says
     * shoppers are searching (the App Store review's run, 17.11). It waits for the first search, and says what waits.
     */
    public function test_a_store_nobody_searched_yet_is_never_told_of_a_steady_week_or_of_shoppers_searching(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($shop);

        $page = Livewire::test(ShopOverview::class)->instance();
        (new \ReflectionProperty($page, 'fixesCache'))->setValue($page, [new TodayFix('k', TodayFix::QUICK, 1, '', 'כותרת', 'טקסט', 'approve', true)]);

        $this->assertFalse($page->firstDay(), 'something waits: not the first day\'s page');
        $this->assertSame('הכול מוכן.', $page->mood());
        $this->assertStringNotContainsString('יציב', $page->mood());
        $this->assertStringStartsWith('קראנו את כל החנות.', $page->lede());
        $this->assertStringNotContainsString('גולשים מחפשים', $page->lede());
        $this->assertStringContainsString('וכמה דברים קטנים יכולים לשפר אותו עוד.', $page->lede());
    }

    public function test_the_panel_opens_in_english_and_keeps_a_chosen_hebrew(): void
    {
        Settings::set('admin.default_locale', 'en');
        $shop = Shop::factory()->create();
        $new = User::factory()->create();
        $new->attachShop($shop);
        $hebrew = User::factory()->locale('he')->create();
        $hebrew->attachShop($shop);

        // a Hebrew browser does not change it: the switch does, and it is remembered
        $this->actingAs($new)->withHeader('Accept-Language', 'he')->get("/merchant/{$shop->slug}/overview")
            ->assertOk()->assertSee('dir="ltr"', false)->assertSee('Search is live.');
        $this->actingAs($hebrew)->get("/merchant/{$shop->slug}/overview")
            ->assertOk()->assertSee('dir="rtl"', false)->assertSee('החיפוש באוויר.');
    }

    public function test_the_period_is_7_days_by_default_then_30_or_90_or_dates(): void
    {
        $shop = Shop::factory()->create(['name' => 'גואטה']);
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($shop);

        $page = Livewire::test(ShopOverview::class)
            ->assertSet('days', 7)
            ->call('setDays', 90)->assertSet('days', 90)
            ->call('setDays', 45)->assertSet('days', 30)
            ->call('setRange', '2026-10-01', '2026-10-10')
            ->assertSet('days', 0)->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-10');

        [$since, $until] = $page->instance()->range();
        $this->assertSame('2026-10-01 00:00:00', $since->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11 00:00:00', $until->format('Y-m-d H:i:s'), 'the last day is included');

        // Backwards or a year and more is not a period.
        $page->call('setRange', '2026-10-10', '2026-10-01')->assertSet('from', '2026-10-01')
            ->call('setRange', '2024-01-01', '2026-10-01')->assertSet('from', '2026-10-01');
    }

    public function test_the_live_feed_shows_only_a_shop_the_person_may_open(): void
    {
        $mine = Shop::factory()->create();
        $theirs = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($mine);
        $this->actingAs($merchant);

        Livewire::test(TodayFeed::class, ['shopId' => (string) $mine->id])->assertOk()->assertSee('data-live-feed', false);
        Livewire::test(TodayFeed::class, ['shopId' => (string) $theirs->id])->assertForbidden();
    }

    public function test_the_weekly_email_can_be_seen_with_the_stores_own_week(): void
    {
        $shop = Shop::factory()->create(['name' => 'גואטה']);
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $this->actingAs($merchant)->get("/merchant/{$shop->slug}/weekly-email")
            ->assertOk()
            ->assertSee('data-weekly-preview', false)
            ->assertSee('המייל השבועי')
            ->assertSee('השבוע שלכם ב-גואטה', false);
    }
}
