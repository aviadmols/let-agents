<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Runs\Jobs\FirstScanStep;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * A scan that stopped on the way (a deploy cut its worker off, a chain that failed) carries on by
 * itself from where it stopped, never from the start and never twice at once.
 */
final class ScanResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scan_whose_chain_is_gone_carries_on_from_its_step(): void
    {
        Bus::fake();
        $shop = Shop::factory()->create();
        FirstScan::start($shop, full: true, skip: ['pictures']);
        $steps = FirstScan::steps($shop);

        // Going on as it should: nothing to do.
        FirstScan::mark($shop->id, $steps[0], FirstScan::RUNNING);
        $this->assertNull(FirstScan::resume($shop));

        // The first step ended a quarter of an hour ago and the next never began: the chain is gone.
        $this->travel(-15)->minutes();
        FirstScan::mark($shop->id, $steps[0], FirstScan::DONE);
        $this->travelBack();
        $this->assertSame($steps[1], FirstScan::resume($shop));
        Bus::assertChained(array_fill(0, count($steps) - 1, FirstScanStep::class));
        $this->assertNull(FirstScan::resume($shop), 'once a quarter hour a shop');
    }

    public function test_a_step_running_past_any_steps_longest_has_stopped(): void
    {
        Bus::fake();
        $shop = Shop::factory()->create();
        FirstScan::start($shop, full: true, skip: ['pictures']);
        $steps = FirstScan::steps($shop);

        $this->travel(-(FirstScan::STALLED_MINUTES + 5))->minutes();
        FirstScan::mark($shop->id, $steps[0], FirstScan::RUNNING);
        $this->travelBack();

        $this->assertSame($steps[0], FirstScan::resume($shop), 'cut off by a deploy: run again');
        $this->assertSame(FirstScan::WAITING, FirstScan::state($shop)['steps'][$steps[0]]);
    }

    /** 15.11: a demo's scan cut off by a deploy stayed "running" for good: scan:resume carried on only active shops. */
    public function test_a_demos_scan_cut_off_by_a_deploy_carries_on_too(): void
    {
        Bus::fake();
        $demo = Shop::factory()->create(['status' => ShopStatus::Demo]);
        $paused = Shop::factory()->create(['status' => ShopStatus::Paused]);
        foreach ([$demo, $paused] as $shop) {
            FirstScan::start($shop, full: true, skip: ['pictures']);
            $this->travel(-(FirstScan::STALLED_MINUTES + 5))->minutes();
            FirstScan::mark($shop->id, FirstScan::steps($shop)[0], FirstScan::RUNNING);
            $this->travelBack();
        }

        $this->artisan('scan:resume')->assertSuccessful();

        $this->assertSame(FirstScan::WAITING, FirstScan::state($demo)['steps'][FirstScan::steps($demo)[0]], 'the demo carries on');
        $this->assertSame(FirstScan::RUNNING, FirstScan::state($paused)['steps'][FirstScan::steps($paused)[0]], 'a paused shop is left as it is');
    }
}
