<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Settings;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages\CreateShop;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages\EditShop;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\Pages\ListShops;
use App\Modules\Tenancy\Filament\Operator\Resources\Shops\RelationManagers\ApiKeysRelationManager;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Tenancy module's shop screens, as the operator uses them in this module's panel.
 * Phase 0 exit criterion: a demo shop is created from the operator panel.
 */
final class OperatorShopScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_the_operator_creates_a_shop_from_the_panel(): void
    {
        Livewire::test(CreateShop::class)
            ->fillForm([
                'name' => 'Demo Tools',
                'domain' => 'https://www.demo-tools.co.il/',
                'platform' => 'woocommerce',
                'status' => 'active',
                'content_locale' => 'he',
                'currency' => 'ILS',
                'timezone' => 'Asia/Jerusalem',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $shop = Shop::query()->sole();
        $this->assertSame('Demo Tools', $shop->name);
        $this->assertSame('demo-tools', $shop->slug);
        $this->assertSame('demo-tools.co.il', $shop->domain);
    }

    public function test_the_shop_list_renders_with_its_shops(): void
    {
        $shops = Shop::factory()->count(3)->create();

        $this->get('/operator/shops')->assertOk();

        Livewire::test(ListShops::class)->assertCanSeeTableRecords($shops);
    }

    public function test_a_new_shop_may_start_on_a_trial_and_the_list_ends_it(): void
    {
        Livewire::test(CreateShop::class)
            ->fillForm(['name' => 'Trial Shop', 'domain' => 'trial-shop.co.il', 'platform' => 'woocommerce', 'status' => 'active',
                'content_locale' => 'he', 'currency' => 'ILS', 'timezone' => 'Asia/Jerusalem', 'trial' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $shop = Shop::query()->sole();
        $this->assertTrue($shop->onTrial());
        $this->assertSame(2000, Settings::get('catalog.max_products', $shop->id));

        Livewire::test(ListShops::class)
            ->assertSee(__('tenancy::shops.trial.left', ['days' => 14]))
            ->callAction(TestAction::make('end_trial')->table($shop));

        $this->assertFalse($shop->refresh()->onTrial());
        $this->assertNull(Settings::overrideFor('catalog.max_products', $shop->id));

        Livewire::test(ListShops::class)->callAction(TestAction::make('start_trial')->table($shop), ['days' => 30]);
        $this->assertSame(30, $shop->refresh()->trialDaysLeft());
    }

    public function test_a_duplicate_domain_is_a_form_error(): void
    {
        Shop::factory()->create(['domain' => 'taken.co.il']);

        Livewire::test(CreateShop::class)
            ->fillForm(['name' => 'Copy', 'domain' => 'taken.co.il', 'platform' => 'woocommerce'])
            ->call('create')
            ->assertHasFormErrors(['domain' => 'unique']);
    }

    public function test_the_operator_issues_a_key_from_the_shop_screen(): void
    {
        $shop = Shop::factory()->create();

        $page = Livewire::test(ApiKeysRelationManager::class, ['ownerRecord' => $shop, 'pageClass' => EditShop::class])
            ->callTableAction('issue', data: ['name' => 'Main site'])
            ->assertHasNoTableActionErrors()
            // shown once, in a window that stays until closed, to copy: not a notification that may vanish
            ->assertActionMounted('showKey');
        $this->assertMatchesRegularExpression('/^usk_[a-z0-9]{8}_[A-Za-z0-9]{40}$/', (string) ($page->instance()->mountedActions[0]['data']['key'] ?? ''));

        $this->assertSame(1, $shop->apiKeys()->active()->count());
    }

    public function test_the_shop_switch_opened_again_is_the_panel_not_an_error(): void
    {
        // A form's address, reloaded or restored with the tab: back to the panel, never "405 Method Not Allowed".
        $this->get('/admin/shop')->assertRedirect('/');
        $this->get('/admin/theme/dark')->assertRedirect('/');
    }

    public function test_a_merchant_user_cannot_open_the_operator_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/operator/shops')->assertForbidden();
    }
}
