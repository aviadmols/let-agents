<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Operator\Pages\DesignLinks;
use App\Modules\Admin\Models\DesignPass;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A designer's day in one shop's panel (Aviad, 29.10): a link the operator makes, shown once, that signs in as the
 * shop's design account (a team member), loads the panel's stylesheet from the designer's computer when asked, and
 * ends with its day or when it is closed.
 */
final class DesignPassTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_designer_comes_in_for_a_day_with_their_own_stylesheet_and_goes_out_with_it(): void
    {
        $shop = Shop::factory()->create(['name' => 'סלע מאיר']);
        Filament::setCurrentPanel(Filament::getPanel(User::OPERATOR_PANEL));
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator);

        $link = Livewire::test(DesignLinks::class)->set('shop', $shop->id)->call('makeLink')->assertSee('data-design-link', false)->get('made');
        $this->assertStringContainsString('/design/', (string) $link);
        $pass = DesignPass::query()->sole();
        $this->assertNotSame(substr((string) $link, -48), $pass->token_hash, 'only its hash is kept');
        Auth::logout();

        // the link: in as the shop's design account, a team member, into the shop's panel
        $this->get((string) $link)->assertRedirect("/merchant/{$shop->slug}/overview");
        $designer = Auth::user();
        $this->assertInstanceOf(User::class, $designer);
        $this->assertSame(ShopRole::Member, $designer->roleIn($shop), 'may change what the team changes, never the people or the account');
        $this->assertFalse($designer->is_operator);
        $this->get("/merchant/{$shop->slug}/overview")->assertOk()->assertSee('data-design-bar', false)->assertSee('data-lets-merchant', false);

        // the stylesheet from the designer's computer, and nothing else
        $this->post('/design/css', ['url' => 'http://localhost:5500/merchant.css'])->assertRedirect();
        $this->get("/merchant/{$shop->slug}/overview")->assertOk()
            ->assertSee('href="http://localhost:5500/merchant.css" media="print" data-lets-merchant-local', false)
            ->assertSee('<style data-lets-merchant>', false);
        $this->post('/design/css', ['url' => 'https://evil.test/x.css']);
        $this->get("/merchant/{$shop->slug}/overview")->assertSee('<style data-lets-merchant>', false);

        // its day over: out, and told so
        $this->travel(25)->hours();
        $this->get("/merchant/{$shop->slug}/overview")->assertRedirect('/design/closed');
        $this->assertNull(Auth::user());
        $this->get((string) $link)->assertOk()->assertSee(__('admin::design.closed.title'));
    }

    public function test_a_link_closed_by_the_operator_lets_no_one_in_and_someone_else_never_sees_the_bar(): void
    {
        $shop = Shop::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel(User::OPERATOR_PANEL));
        $this->actingAs(User::factory()->operator()->create());
        $page = Livewire::test(DesignLinks::class)->set('shop', $shop->id)->call('makeLink');
        $link = (string) $page->get('made');
        $page->call('revoke', DesignPass::query()->sole()->id)->assertSee(__('admin::design.none'));
        Auth::logout();

        $this->get($link)->assertOk()->assertSee(__('admin::design.closed.title'));
        $this->assertNull(Auth::user());

        $merchant = User::factory()->create();
        $merchant->attachShop($shop, ShopRole::Owner);
        $this->actingAs($merchant)->get("/merchant/{$shop->slug}/overview")->assertOk()->assertDontSee('data-design-bar', false);
        $this->post('/design/css', ['url' => 'http://localhost:5500/merchant.css'])->assertForbidden();
    }
}
