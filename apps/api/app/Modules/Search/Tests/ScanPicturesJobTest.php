<?php

namespace App\Modules\Search\Tests;

use App\Modules\Retrieval\Contracts\CaptionsPictures;
use App\Modules\Retrieval\Contracts\RunsRetrieval;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Jobs\ScanPicturesJob;
use Tests\TestCase;

/** A scan that stops on its time budget goes on in the next part, until it is done. */
final class ScanPicturesJobTest extends TestCase
{
    public function test_a_scan_goes_on_in_parts_until_every_picture_is_done(): void
    {
        $fake = new class implements RunsRetrieval
        {
            public int $parts = 0;

            public function index(string $shopId): Run
            {
                throw new \LogicException('not here');
            }

            public function images(string $shopId): Run
            {
                $this->parts++;

                return (new Run)->forceFill(['output' => ['stopped' => [1 => 'more', 2 => 'time_budget'][$this->parts] ?? null]]);
            }

            public function match(string $shopId, ?array $productIds = null): Run
            {
                throw new \LogicException('not here');
            }
        };
        $this->app->instance(RunsRetrieval::class, $fake);
        $this->app->instance(CaptionsPictures::class, new class implements CaptionsPictures
        {
            public function captions(string $shopId): Run
            {
                return (new Run)->forceFill(['output' => ['stopped' => null]]);
            }
        });

        ScanPicturesJob::dispatch('shop-1');

        $this->assertSame(3, $fake->parts);
        $this->assertGreaterThan(1200, (new ScanPicturesJob('x'))->timeout, 'the worker never cuts a part off before its own budget');
    }
}
