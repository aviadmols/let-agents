<?php

namespace App\Modules\Admin\Tests;

use App\Core\Facades\Features;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Actions\IssueApiKey;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_each_panels_login(): void
    {
        $this->get('/operator/shops')->assertRedirect('/operator/login');
        $this->get('/operator/login')->assertOk();
        $this->get('/merchant/login')->assertOk();
    }

    public function test_the_operator_panel_is_for_operators_only(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get('/operator/configuration')
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get('/operator/configuration')
            ->assertForbidden();
    }

    public function test_a_merchant_sees_their_own_shop(): void
    {
        $shop = Shop::factory()->create(['name' => 'Tools IL']);
        app(IssueApiKey::class)->handle($shop, 'plugin');
        $merchant = User::factory()->create();
        $merchant->attachShop($shop, ShopRole::Owner);

        $this->actingAs($merchant)
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee('Tools IL');

        // The connection details live under settings now.
        $this->get("/merchant/{$shop->slug}/settings/connection")->assertOk()->assertSee($shop->domain);
    }

    public function test_a_merchant_cannot_open_another_shop(): void
    {
        $mine = Shop::factory()->create();
        $theirs = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($mine);

        $this->actingAs($merchant)
            ->get("/merchant/{$theirs->slug}/overview")
            ->assertNotFound();
    }

    public function test_a_user_with_no_shops_has_no_merchant_panel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/merchant')
            ->assertForbidden();
    }

    public function test_turning_off_the_merchant_panel_flag_locks_that_shops_merchants_out(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        Features::override('admin.merchant_panel', false, $shop->id);

        $this->actingAs($merchant)
            ->get("/merchant/{$shop->slug}/overview")
            ->assertNotFound();
    }

    public function test_a_disabled_shop_is_closed_to_its_merchants_but_not_to_operators(): void
    {
        $shop = Shop::factory()->disabled()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $this->actingAs($merchant)->get("/merchant/{$shop->slug}/overview")->assertNotFound();

        $this->actingAs(User::factory()->operator()->create())
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk();
    }
}
