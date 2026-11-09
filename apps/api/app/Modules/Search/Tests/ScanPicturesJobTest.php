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

    public function test_the_picture_model_failing_on_some_pictures_holds_back_no_description_of_the_others(): void
    {
        // Take Me Home, 9.11: the gallery failed at the picture model, and none of the 89 pictures it saw was described
        $this->app->instance(RunsRetrieval::class, new class implements RunsRetrieval
        {
            public function index(string $shopId): Run
            {
                throw new \LogicException('not here');
            }

            public array $stops = ['provider_error', 'already_running'];

            public function images(string $shopId): Run
            {
                return (new Run)->forceFill(['output' => ['stopped' => array_shift($this->stops)]]);
            }

            public function match(string $shopId, ?array $productIds = null): Run
            {
                throw new \LogicException('not here');
            }
        });
        $captions = new class implements CaptionsPictures
        {
            public int $asked = 0;

            public function captions(string $shopId): Run
            {
                $this->asked++;

                return (new Run)->forceFill(['output' => ['stopped' => null]]);
            }
        };
        $this->app->instance(CaptionsPictures::class, $captions);

        ScanPicturesJob::dispatch('shop-1');
        $this->assertSame(1, $captions->asked);

        // and a scan whose pictures another holds (a lock a deploy left behind): those seen are described all the same
        ScanPicturesJob::dispatch('shop-1');
        $this->assertSame(2, $captions->asked);
    }
}
