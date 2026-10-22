<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\ConnectionSettings;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Settings · Connection and plan: the plans side by side, the shop's own marked, an upgrade or a downgrade to each of
 * the others. A store no platform bills asks the team, who are told which plan.
 */
final class PlanChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->shop = Shop::factory()->create(['name' => 'רוקס', 'plan' => 'starter']);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $owner->attachShop($this->shop, ShopRole::Owner);
        User::factory()->create(['email' => 'team@lets.test', 'is_operator' => true]);
        $this->actingAs($owner);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
    }

    public function test_the_plans_show_side_by_side_with_the_shops_own_marked(): void
    {
        $plans = collect(Livewire::test(ConnectionSettings::class)->assertSee('התוכניות')->assertSee('התוכנית שלכם')->instance()->details()['plans'])->keyBy('code');

        $this->assertTrue($plans['starter']['current']);
        $this->assertSame('upgrade', $plans['growth']['move']);
        $this->assertSame('downgrade', $plans['essentials']['move']);
        $this->assertSame('$99', $plans['starter']['price']);
    }

    public function test_a_store_the_team_bills_asks_the_team_for_another_plan(): void
    {
        Livewire::test(ConnectionSettings::class)->call('askPlan', 'growth')->assertNotified('קיבלנו. נעביר את החנות לתוכנית Growth עוד היום.');

        Mail::assertSent(TeamNoticeMail::class, fn (TeamNoticeMail $mail): bool => $mail->kind === TeamNoticeMail::PLAN_ASKED && $mail->plan === 'Growth' && $mail->hasTo('team@lets.test'));
    }

    public function test_only_the_owner_moves_the_shop_to_another_plan(): void
    {
        $member = User::factory()->create();
        $member->attachShop($this->shop, ShopRole::Member);
        $this->actingAs($member);

        Livewire::test(ConnectionSettings::class)->assertDontSee('data-plan-move', false)->call('askPlan', 'growth');

        Mail::assertNotSent(TeamNoticeMail::class);
    }
}
