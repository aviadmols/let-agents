<?php

namespace App\Modules\Ai\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\CurrentShop;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Filament\Operator\Pages\ShopBudgets;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Each shop has its own monthly model allowance, $30 unless an operator changes it. Background work
 * stops at the end of it; what shoppers ask goes on unless the operator says otherwise. The shop
 * manager sees only the share used, the operator sees dollars and is the only one who changes it.
 */
final class ShopAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-13 12:00:00', 'UTC'));
        Settings::set('ai.monthly_spend_cap_usd', 1000);
    }

    public function test_background_work_stops_at_the_shops_allowance_and_shoppers_are_still_answered(): void
    {
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();
        $guard = app(SpendGuard::class);

        $this->assertSame(30.0, $guard->capFor($shop->id), 'thirty dollars a month by default');

        $this->spend($shop, 29.5, '2026-10-02 08:00:00');
        $this->spend($shop, 40.0, '2026-09-28 08:00:00'); // last month
        $this->spend($other, 25.0, '2026-10-05 08:00:00'); // another shop's

        $this->assertEqualsWithDelta(29.5, $guard->spentThisMonthFor($shop->id), 0.0001);

        $inShop = fn (callable $call) => app(TenantContext::class)->run($shop->id, $call);

        $inShop(fn () => $guard->assertCanSpend(0.4));

        try {
            $inShop(fn () => $guard->assertCanSpend(0.6));
            $this->fail('the night work should have been refused');
        } catch (SpendCapReached $refused) {
            $this->assertSame($shop->id, $refused->shopId);
            $this->assertSame(30.0, $refused->capUsd);
        }

        $inShop(fn () => $guard->assertCanSpend(0.6, forShopper: true));

        Settings::set('ai.shop_cap_stops_shoppers', true, $shop->id);
        $this->expectException(SpendCapReached::class);
        $inShop(fn () => $guard->assertCanSpend(0.6, forShopper: true));
    }

    public function test_the_shop_manager_sees_a_share_and_never_an_amount(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $this->spend($shop, 12.6, '2026-10-03 08:00:00');

        // Below the warning line the strip stays away; the home's card shows the share.
        $this->actingAs($merchant)->withHeader('Accept-Language', 'he')
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertDontSee('data-usage-strip', false)
            ->assertSee('data-card="ai"', false)
            ->assertSee('42%');

        $this->spend($shop, 15.0, '2026-10-04 08:00:00');

        $html = $this->actingAs($merchant)
            ->withHeader('Accept-Language', 'he')
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee('data-usage-strip', false)
            ->assertSee('92% מהמכסה החודשית')
            ->assertSee('מתחדש ב־1 בנובמבר')
            ->getContent();

        preg_match('/<div class="la-usage".*?<\/div>/s', $html, $strip);
        $this->assertStringNotContainsString('$', $strip[0], 'no dollars on a merchant screen');
        $this->assertStringNotContainsString('27.6', $strip[0]);
    }

    public function test_the_operator_sees_dollars_and_changes_a_shops_allowance(): void
    {
        $shop = Shop::factory()->create(['name' => 'גואטה']);
        $this->spend($shop, 27.6, '2026-10-04 08:00:00');
        $operator = User::factory()->operator()->locale('he')->create();

        $this->actingAs($operator)
            ->withSession([CurrentShop::SESSION_KEY => $shop->id])
            ->get('/operator/budgets')
            ->assertOk()
            ->assertSee('$27.60 מתוך $30.00 (92%)')
            ->assertSee('קרוב למכסה');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        Livewire::actingAs($operator)->test(ShopBudgets::class)
            ->call('edit', $shop->id)
            ->assertSet('cap', '30')
            ->set('cap', '45')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', null);

        $this->assertSame(45.0, app(SpendGuard::class)->capFor($shop->id));
        $this->assertSame(30.0, app(SpendGuard::class)->capFor(Shop::factory()->create()->id), 'only that shop');

        Livewire::actingAs($operator)->test(ShopBudgets::class)
            ->call('edit', $shop->id)
            ->call('useDefault');

        $this->assertSame(30.0, app(SpendGuard::class)->capFor($shop->id));
    }

    private function spend(Shop $shop, float $cost, string $at): void
    {
        Run::query()->create([
            'shop_id' => $shop->id,
            'agent' => 'retrieval.image_reader', 'action' => 'retrieval.read_images', 'status' => 'succeeded', 'trigger' => 'system',
            'provider' => 'anthropic', 'cost_usd' => $cost,
            'started_at' => $at, 'created_at' => $at,
        ]);
    }
}
