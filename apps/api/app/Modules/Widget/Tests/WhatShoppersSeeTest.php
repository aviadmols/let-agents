<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Filament\Merchant\Pages\WhatShoppersSee;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What shoppers see (the redesign, 11.2026): every control saves at once to the modules' own keys, the shop's word kept
 * only where it differs from what it inherits; the camera starts the pictures' reading; hand-over to WhatsApp needs a
 * number and turns the search's "ask the team" on; the number is kept international; a viewer changes nothing. That
 * the store is told (suggestions while typing among the rest) is SearchDisplayTest's.
 */
final class WhatShoppersSeeTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['domain' => 'www.maisonvale.test']);
        $merchant = User::factory()->create(['locale' => 'en']);
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($this->shop);
        app()->setLocale('en');
    }

    public function test_the_screen_shows_every_group_and_the_preview(): void
    {
        $this->get("/merchant/{$this->shop->slug}/see")
            ->assertOk()
            ->assertSee('Live preview · maisonvale.test')
            ->assertSee('Saves automatically')
            ->assertSee('The look')
            ->assertSee('The search window')
            ->assertSee('Save and compare')
            ->assertSee('When the assistant doesn’t know', false)
            ->assertSee("Sun–Thu \u{2066}9:00–18:00\u{2069}");
    }

    public function test_each_control_saves_at_once_and_only_where_it_differs(): void
    {
        $id = $this->shop->id;
        $page = Livewire::test(WhatShoppersSee::class);

        $page->call('choose', 'look', 'store')->call('choose', 'fit', 'all');
        $this->assertSame(['store', 'frame'], [Settings::get('search.theme', $id), Settings::get('search.image_fit', $id)]);
        $page->call('choose', 'look', 'lets');
        $this->assertNull(Settings::overrideFor('search.theme', $id), 'back to what it inherits: no word of its own');

        $page->call('turn', 'suggest')->call('turn', 'price')->call('turn', 'heart')->call('turn', 'photos');
        $this->assertFalse(Features::enabled('search.suggest', $id));
        $this->assertFalse(Settings::get('search.show_price', $id));
        $this->assertTrue(Features::enabled('shortlist.wishlist', $id));
        $this->assertTrue(Features::enabled('search.photos', $id));
        $this->assertTrue(Features::enabled('retrieval.image_index', $id), 'the camera needs the pictures read');
        $this->assertSame(['suggest' => false, 'photos' => true, 'price' => false, 'heart' => true], array_intersect_key($page->instance()->switches(), array_flip(['suggest', 'price', 'heart', 'photos'])));
        $page->call('choose', 'look', 'nope')->call('turn', 'nope');
    }

    public function test_hand_over_to_whatsapp_needs_a_number_kept_international(): void
    {
        $id = $this->shop->id;
        $page = Livewire::test(WhatShoppersSee::class)->call('turn', 'whatsapp');
        $this->assertFalse($page->instance()->switches()['whatsapp'], 'no number, no hand-over');

        $page->set('number', '12')->call('saveNumber');
        $this->assertNull(Settings::overrideFor('widget.whatsapp_number', $id));

        $page->set('number', '050-123 4567')->call('saveNumber')->assertSet('number', '+972501234567');
        $page->call('turn', 'whatsapp');
        $this->assertTrue($page->instance()->switches()['whatsapp']);
        $this->assertTrue(Features::enabled('widget.whatsapp', $id));
        $this->assertSame('team', Settings::get('assistant.no_answer', $id));

        // the number stays while hand-over is on; off, it may go, and the WhatsApp button with it
        $page->set('number', '')->call('saveNumber');
        $this->assertSame('972501234567', Settings::overrideFor('widget.whatsapp_number', $id));
        $page->call('turn', 'whatsapp')->set('number', '')->call('saveNumber');
        $this->assertNull(Settings::overrideFor('widget.whatsapp_number', $id));
        $this->assertFalse(Features::enabled('widget.whatsapp', $id));
    }

    /**
     * A control the plan holds says so where it stands: "Requires upgrade" beside it, the way to the plan page, and it
     * stays as the plan has it. A shop whose plan holds nothing sees no such mark.
     */
    public function test_a_control_the_plan_holds_is_marked_as_needing_an_upgrade(): void
    {
        $url = "/merchant/{$this->shop->slug}/see";
        $this->get($url)->assertOk()->assertDontSee('Requires upgrade');

        // Starter: the store's own look comes with Growth and up
        $this->shop->forceFill(['plan' => 'starter'])->save();
        $page = Livewire::test(WhatShoppersSee::class);
        $this->assertContains('look', $page->instance()->held());
        $this->get($url)->assertOk()
            ->assertSee('Requires upgrade')
            ->assertSee('lp-d-up', false)
            ->assertSee("/merchant/{$this->shop->slug}/settings/plan", false);

        $page->call('choose', 'look', 'store');
        $this->assertSame('lets', Settings::get('search.theme', $this->shop->id), 'the plan still holds it');
    }

    public function test_a_viewer_changes_nothing(): void
    {
        $viewer = User::factory()->create(['locale' => 'en']);
        $viewer->attachShop($this->shop, ShopRole::Viewer);
        $this->actingAs($viewer);

        Livewire::test(WhatShoppersSee::class)->call('turn', 'heart')->call('choose', 'look', 'store');

        $this->assertFalse(Features::enabled('shortlist.wishlist', $this->shop->id));
        $this->assertSame('lets', Settings::get('search.theme', $this->shop->id));
    }
}
