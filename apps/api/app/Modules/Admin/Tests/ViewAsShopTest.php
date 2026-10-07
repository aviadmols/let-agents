<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\CurrentShop;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator opens any shop exactly as its manager sees it: one button in the top bar once a
 * shop is chosen, and a banner there that says whose shop it is and from which chair.
 */
final class ViewAsShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_top_bar_offers_the_chosen_shop_as_its_manager_sees_it(): void
    {
        $shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        Shop::factory()->create();
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->get('/operator/agents')->assertOk()->assertDontSee('data-view-as-shop', false);

        $this->actingAs($operator)
            ->withSession([CurrentShop::SESSION_KEY => $shop->id])
            ->get('/operator/agents')
            ->assertOk()
            ->assertSee('data-view-as-shop', false)
            ->assertSee(url("/merchant/{$shop->slug}"), false)
            ->assertSee(__('admin::panels.view_as_shop'));
    }

    public function test_the_button_leads_to_the_shop_panel_with_a_banner_that_says_whose_it_is(): void
    {
        $shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->followingRedirects()
            ->get("/merchant/{$shop->slug}")
            ->assertOk()
            ->assertSee('data-shop-banner="'.$shop->slug.'"', false)
            ->assertSee(__('admin::panels.account.as_operator', ['shop' => 'גואטה אביגדור']));
    }

    public function test_a_shop_manager_never_gets_the_button_or_another_shop(): void
    {
        $mine = Shop::factory()->create();
        $other = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($mine);

        $this->actingAs($merchant)->followingRedirects()->get("/merchant/{$mine->slug}")->assertOk()->assertDontSee('data-view-as-shop', false);
        $this->actingAs($merchant)->get("/merchant/{$other->slug}")->assertNotFound();
        $this->actingAs($merchant)->get("/operator/agents")->assertForbidden();
    }
}
