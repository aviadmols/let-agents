<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operators land in the operator panel and can always get back to it; merchants land in theirs.
 */
final class PanelNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_site_root_sends_each_person_to_their_panel(): void
    {
        $this->get('/')->assertRedirect('/merchant/login');

        $this->actingAs(User::factory()->operator()->create())->get('/')->assertRedirect('/operator');

        $merchant = User::factory()->create();
        $merchant->attachShop(Shop::factory()->create(), ShopRole::Owner);
        $this->actingAs($merchant)->get('/')->assertRedirect('/merchant');
    }

    public function test_an_operator_signing_in_on_the_merchant_login_lands_in_the_operator_panel(): void
    {
        $operator = User::factory()->operator()->create();

        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $operator->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(url('/operator'));
    }

    public function test_a_merchant_signing_in_lands_in_the_merchant_panel(): void
    {
        $merchant = User::factory()->create();
        $merchant->attachShop(Shop::factory()->create());

        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $merchant->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(url('/merchant'));
    }

    public function test_a_specific_page_that_required_login_still_wins(): void
    {
        $operator = User::factory()->operator()->create();
        $shop = Shop::factory()->create();

        $this->get("/merchant/{$shop->slug}/overview")->assertRedirect();
        $this->assertSame(url("/merchant/{$shop->slug}/overview"), session('url.intended'));

        Filament::setCurrentPanel(Filament::getPanel('merchant'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $operator->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(url("/merchant/{$shop->slug}/overview"));
    }

    public function test_operators_viewing_a_shop_see_the_way_back_and_merchants_do_not(): void
    {
        $shop = Shop::factory()->create();
        $url = "/merchant/{$shop->slug}/overview";

        foreach (['he', 'en'] as $locale) {
            $this->actingAs(User::factory()->operator()->locale($locale)->create())
                ->get($url)
                ->assertOk()
                ->assertSee('data-operator-shortcut', false)
                ->assertSee(__('admin::panels.switch.operator', [], $locale));
        }

        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $this->actingAs($merchant)
            ->get($url)
            ->assertOk()
            ->assertDontSee('data-operator-shortcut', false)
            ->assertDontSee(__('admin::panels.switch.operator'));
    }

    public function test_the_shops_list_links_to_each_shops_merchant_view(): void
    {
        $shop = Shop::factory()->create();

        $this->actingAs(User::factory()->operator()->create())
            ->get('/operator/shops')
            ->assertOk()
            ->assertSee(url("/merchant/{$shop->slug}/overview"), false)
            ->assertSee(__('admin::panels.switch.merchant'));
    }
}
