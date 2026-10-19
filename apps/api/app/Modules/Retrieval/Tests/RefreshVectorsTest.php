<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Features;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Retrieval\Jobs\RefreshVectorsJob;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A product the store changed today is found by its new words today: a sync queues one refresh of the
 * shop's vectors a few minutes later, one per shop however many changes come in those minutes.
 */
final class RefreshVectorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_change_queues_one_delayed_refresh_of_the_shops_vectors(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();

        event(new CatalogProductsChanged($shop->id, ['55562']));

        Queue::assertPushed(RefreshVectorsJob::class, fn (RefreshVectorsJob $job): bool => $job->shopId === $shop->id && $job->delay !== null);
        $job = new RefreshVectorsJob($shop->id);
        $this->assertSame($shop->id, $job->uniqueId(), 'one at a time a shop: a burst of edits is one run');
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
    }

    public function test_a_run_that_left_pieces_waiting_is_followed_at_once_not_at_night(): void
    {
        $this->assertTrue(RefreshVectorsJob::more(['embedded' => 5000, 'pending' => 1200, 'stopped' => null]), 'a whole catalogue read again: the next run now');
        $this->assertFalse(RefreshVectorsJob::more(['embedded' => 300, 'pending' => 0, 'stopped' => null]));
        $this->assertFalse(RefreshVectorsJob::more(['embedded' => 100, 'pending' => 900, 'stopped' => 'spend_cap']), 'the spending cap waits for the night');
        $this->assertFalse(RefreshVectorsJob::more(['embedded' => 0, 'pending' => 900, 'stopped' => null]), 'nothing went through: no loop');
        $this->assertNotSame((new RefreshVectorsJob('s'))->uniqueId(), (new RefreshVectorsJob('s', 1))->uniqueId(), 'the next run is not held back by the first');
    }

    public function test_nothing_is_queued_when_the_shop_turned_it_off(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        Features::override('retrieval.refresh_on_change', false, $shop->id);

        event(new CatalogProductsChanged($shop->id, ['55562']));

        Queue::assertNotPushed(RefreshVectorsJob::class);
    }
}
