<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Modules\Admin\Models\User;
use App\Modules\Search\Filament\Operator\Pages\ShopSearches;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Search by photo is the system operator's choice, site by site: off until turned on, and the
 * shop's own screen never shows the switch.
 */
final class PhotoSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_turns_photo_search_on_for_one_site_only(): void
    {
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();

        $this->assertFalse(Features::enabled('search.photos', $shop->id), 'off until the operator turns it on');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ShopSearches::class, ['shop' => $shop->id])
            ->assertSee('חיפוש לפי תמונה באתר הזה')
            ->assertSee('סגור באתר הזה')
            ->call('setPhotos', true)
            ->assertSee('פתוח באתר הזה');

        $this->assertTrue(Features::enabled('search.photos', $shop->id));
        $this->assertTrue(Features::enabled('retrieval.image_index', $shop->id), 'turning photo search on starts the picture scan');
        $this->assertFalse(Features::enabled('search.photos', $other->id), 'the other site is untouched');
    }

    public function test_the_shop_manager_sees_the_status_but_never_the_switch(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $this->actingAs($merchant)
            ->get("/merchant/{$shop->slug}/search/terms")
            ->assertOk()
            ->assertSee(__('search::ui.photos.heading'))
            ->assertSee(__('search::ui.photos.no_pictures'))
            ->assertSee(__('search::ui.photos.ask_us'))
            ->assertDontSee('setPhotos', false);

        $this->assertFalse(Features::enabled('search.photos', $shop->id));
    }
}
