<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shop manager's home, as the designer's panel lays it out: the greeting with the period, the
 * five first steps, four cards, the period's numbers and what needs the manager; and the notices on a
 * screen of their own. All of it from the modules' sources, none of it about models or money spent on them.
 */
final class MerchantHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_shows_the_steps_the_cards_the_numbers_and_what_waits(): void
    {
        $shop = Shop::factory()->create(['name' => 'ספרים ובגדים']);
        $merchant = User::factory()->create(['name' => 'דנה כהן']);
        $merchant->attachShop($shop);

        $html = $this->actingAs($merchant)->withHeader('Accept-Language', 'he')
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee(', דנה')
            ->assertSee('ספרים ובגדים · מה קרה ב-30 הימים האחרונים')
            ->assertSee('4 צעדים ראשונים')
            ->assertSee('מתוך 4')
            ->assertSeeInOrder(['data-step="on"', 'data-step="look"', 'data-step="answer"'], false)
            ->assertDontSee('להזמין את הצוות')
            ->assertSee('data-card="questions"', false)
            ->assertSee('data-card="ai"', false)
            ->assertSee('data-card="products"', false)
            ->assertSee('data-card="pages"', false)
            ->assertSee('data-number="revenue"', false)
            ->assertSee('דורש את תשומת לבכם')
            ->assertDontSee('מה מופעל בחנות')
            ->getContent();

        preg_match('/<div class="lp-page" data-home>.*/s', $html, $page);
        $this->assertStringNotContainsString('$', strip_tags($page[0]), 'no dollar on the shop manager\'s home');

        $this->get("/merchant/{$shop->slug}/notices")->assertOk()->assertSee('הודעות ותובנות');
        $this->get("/merchant/{$shop->slug}/connection")->assertOk()->assertSee('חיבור החנות');
    }

    public function test_the_period_is_7_30_or_90_days_or_dates_the_manager_chose(): void
    {
        $shop = Shop::factory()->create(['name' => 'גואטה']);
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($shop);

        $page = Livewire::test(ShopOverview::class)
            ->call('setDays', 7)->assertSet('days', 7)
            ->call('setDays', 45)->assertSet('days', 30)
            ->call('setRange', '2026-10-01', '2026-10-10')
            ->assertSet('days', 0)->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-10')
            ->assertSee('גואטה · מה קרה בין 1.10 ל-10.10');

        [$since, $until] = $page->instance()->range();
        $this->assertSame('2026-10-01 00:00:00', $since->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11 00:00:00', $until->format('Y-m-d H:i:s'), 'the last day is included');

        // Backwards or a year and more is not a period.
        $page->call('setRange', '2026-10-10', '2026-10-01')->assertSet('from', '2026-10-01')
            ->call('setRange', '2024-01-01', '2026-10-01')->assertSet('from', '2026-10-01');
    }
}
