<?php

namespace App\Modules\Search\Tests;

use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Search\Jobs\RebuildSearchIndexJob;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The changes of a minute build a shop's search index once (9-11.11: 302 whole builds in three days, up to three in
 * one minute), after the minute, and another shop's changes build its own.
 */
final class RebuildIndexOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_together_build_the_index_once_per_shop(): void
    {
        Queue::fake();
        [$one, $two] = [Shop::factory()->create(), Shop::factory()->create()];

        event(new CatalogProductsChanged($one->id, ['1']));
        event(new CatalogProductsChanged($one->id, ['2']));
        event(new CatalogUpdated($one->id));
        event(new CatalogProductsChanged($two->id, ['3']));

        Queue::assertPushed(RebuildSearchIndexJob::class, 2);
        Queue::assertPushed(RebuildSearchIndexJob::class, fn (RebuildSearchIndexJob $job): bool => $job->shopId === $one->id && $job->delay !== null);
        Queue::assertPushed(RebuildSearchIndexJob::class, fn (RebuildSearchIndexJob $job): bool => $job->shopId === $two->id);
    }
}
