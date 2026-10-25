<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Runs\Contracts\ScansShops;
use App\Modules\Runs\Filament\Merchant\Widgets\FirstScanCard;
use App\Modules\Runs\Jobs\FirstScanStep;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

    public function test_the_card_says_what_each_step_did_without_models_or_money(): void
    {
        $shop = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        Cache::put("first-scan:{$shop->id}:started", now()->toIso8601String());
        FirstScan::mark($shop->id, 'catalog', FirstScan::RUNNING);

        $run = fn (string $key, array $params) => Run::query()->create(['shop_id' => $shop->id, 'agent' => 'catalog.sync', 'action' => 'catalog.sync', 'status' => 'succeeded', 'trigger' => 'manual', 'summary_key' => $key, 'summary_params' => $params, 'started_at' => now(), 'created_at' => now()]);
        $run('catalog::runs.synced', ['products' => '1,240', 'created' => '30', 'updated' => '2', 'removed' => '0', 'categories' => '12', 'content' => '4']);
        $run('ai::budget.cap_reached', ['spent' => '5.00', 'estimate' => '0.10', 'cap' => '6.00']);
        FirstScan::mark($shop->id, 'catalog', FirstScan::DONE);

        $notes = FirstScan::notes($shop);
        $this->assertSame(['נקראו 1,240 מוצרים: 30 חדשים, 2 השתנו, 0 כבר לא מפורסמים. 12 קטגוריות, 4 מאמרים.'], $notes['catalog']);

        $this->actingAs($merchant)->withHeader('Accept-Language', 'he')
            ->get("/merchant/{$shop->slug}/overview")
            ->assertOk()
            ->assertSee('נקראו 1,240 מוצרים')
            ->assertDontSee('התקרה החודשית');
    }

    public function test_after_the_first_the_manager_scans_the_changes_and_only_an_operator_scans_it_all(): void
    {
        $shop = Shop::factory()->create();
        // The night has scanned it: no longer new.
        Run::query()->create(['shop_id' => $shop->id, 'agent' => 'catalog.sync', 'action' => 'catalog.sync', 'status' => 'succeeded', 'trigger' => 'schedule', 'started_at' => now()->subDay(), 'created_at' => now()->subDay()]);
        $merchant = User::factory()->create();
        $merchant->attachShop($shop);
        Bus::fake();
        $this->actingAs($merchant);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($shop);

        $operator = User::factory()->operator()->create();
        Livewire::actingAs($operator)->test(FirstScanCard::class)->assertSee('סריקה מלאה מחדש');

        // The manager's card left the home with the first scan; they scan the changes from the scan summary.
        $this->actingAs($merchant);
        $this->assertFalse(FirstScanCard::canView());
        app(ScansShops::class)->scanChanges($shop);

        Bus::assertChained(array_fill(0, count(FirstScan::CHANGES), FirstScanStep::class));
        $this->assertSame(FirstScan::CHANGES, FirstScan::steps($shop), 'the changes only: catalogue, pictures, products, pages, search');

        Livewire::actingAs($operator)->test(FirstScanCard::class)->call('startFull');
        $this->assertSame(array_keys(FirstScan::STEPS), FirstScan::steps($shop), 'the operator scans it all, even while the manager is locked');
    }
}
