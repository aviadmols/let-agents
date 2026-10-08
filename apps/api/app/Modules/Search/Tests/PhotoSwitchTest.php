<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Modules\Admin\Models\User;
use App\Modules\Retrieval\Contracts\RunsRetrieval;
use App\Modules\Runs\Models\Run;
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

    public function test_the_operator_scans_the_pictures_now(): void
    {
        $shop = Shop::factory()->create();
        $fake = new class implements RunsRetrieval
        {
            /** @var list<string> */
            public array $scanned = [];

            public function index(string $shopId): Run
            {
                throw new \LogicException('not here');
            }

            public function images(string $shopId): Run
            {
                $this->scanned[] = $shopId;

                return new Run;
            }

            public function match(string $shopId, ?array $productIds = null): Run
            {
                throw new \LogicException('not here');
            }
        };
        $this->app->instance(RunsRetrieval::class, $fake);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(ShopSearches::class, ['shop' => $shop->id])
            ->assertSee(__('search::ui.photos.scan_now'))
            ->call('scanPicturesNow')
            ->assertNotified(__('search::ui.photos.scan_started'));

        $this->assertSame([$shop->id], $fake->scanned, 'the scan runs for this shop, on the queue');
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
            ->assertDontSee('setPhotos', false)
            ->assertDontSee('scanPicturesNow', false);

        $this->assertFalse(Features::enabled('search.photos', $shop->id));
    }

    public function test_the_shop_manager_scans_once_photo_search_is_on_and_sees_it_wait(): void
    {
        $shop = Shop::factory()->create();
        Features::override('search.photos', true, $shop->id);
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        $fake = new class implements RunsRetrieval
        {
            public int $scans = 0;

            public function index(string $shopId): Run
            {
                throw new \LogicException('not here');
            }

            public function images(string $shopId): Run
            {
                $this->scans++;

                return new Run;
            }

            public function match(string $shopId, ?array $productIds = null): Run
            {
                throw new \LogicException('not here');
            }
        };
        $this->app->instance(RunsRetrieval::class, $fake);

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs($merchant);
        Livewire::test(ShopSearches::class, ['shop' => $shop->id])
            ->assertSee(__('search::ui.photos.scan_now'))
            ->assertDontSee('setPhotos', false)
            ->call('scanPicturesNow')
            ->assertNotified(__('search::ui.photos.scan_started'))
            ->assertSee(__('search::ui.photos.queued'))
            ->call('scanPicturesNow')
            ->assertNotified(__('search::ui.photos.scan_wait'));

        $this->assertSame(1, $fake->scans, 'pressing again within the hour queues nothing');
    }

    public function test_the_shop_manager_cannot_scan_while_photo_search_is_off(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        $this->actingAs($merchant);
        Livewire::test(ShopSearches::class, ['shop' => $shop->id])
            ->assertDontSee(__('search::ui.photos.scan_now'))
            ->call('scanPicturesNow')
            ->assertForbidden();
    }
}
