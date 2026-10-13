<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Runs\Filament\Merchant\Widgets\FirstScanCard;
use App\Modules\Runs\Jobs\FirstScanStep;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A new shop's overview opens on the scan: one button starts the night's work for this shop now,
 * step after step on the long queue, and the card says where it is, in the shop's words.
 */
final class FirstScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_shops_overview_offers_the_scan_and_the_button_queues_every_step_once(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);

        $this->actingAs($merchant)->withHeader('Accept-Language', 'he')
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee('data-first-scan', false)
            ->assertSee('להתחיל סריקה ולמידה')
            ->assertSee('בניית החיפוש');

        Bus::fake();
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);

        Livewire::test(FirstScanCard::class)->call('start')->assertSee('סורקים ולומדים את החנות');

        Bus::assertChained(array_fill(0, count(FirstScan::STEPS), FirstScanStep::class));
        $this->assertFalse(FirstScan::canStart($shop), 'once, until it has had time to run');
        $this->assertFalse(FirstScan::start($shop));
    }

    public function test_each_step_runs_the_nights_command_for_this_shop_and_marks_where_it_is(): void
    {
        $shop = Shop::factory()->create(['slug' => 'books']);
        FirstScan::mark($shop->id, 'search', FirstScan::WAITING);
        Artisan::command('search {step} {target?} {--all}', fn () => 0);

        (new FirstScanStep($shop->id, 'search', 'search', ['step' => 'index', 'target' => 'books']))->handle();

        $this->assertSame(FirstScan::DONE, cache()->get("first-scan:{$shop->id}:step:search"));
    }
}
