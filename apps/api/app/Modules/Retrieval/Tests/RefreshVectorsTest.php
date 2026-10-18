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

    public function test_nothing_is_queued_when_the_shop_turned_it_off(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        Features::override('retrieval.refresh_on_change', false, $shop->id);

        event(new CatalogProductsChanged($shop->id, ['55562']));

        Queue::assertNotPushed(RefreshVectorsJob::class);
    }
}
