<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Jobs\ScanPicturesJob;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A picture scan cut off by a deploy or a crash goes on by itself until every picture is read. */
final class ResumePictureScansTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shop_with_pictures_left_and_no_scan_running_gets_its_scan_back(): void
    {
        Queue::fake();
        $waiting = Shop::factory()->create();
        $done = Shop::factory()->create();
        $off = Shop::factory()->create();
        foreach ([$waiting, $done, $off] as $shop) {
            Features::override('retrieval.image_index', $shop !== $off, $shop->id);
        }

        app(TenantContext::class)->runUnscoped(function () use ($waiting, $done, $off): void {
            foreach ([[$waiting, 3, 1], [$done, 2, 2], [$off, 2, 0]] as [$shop, $products, $scanned]) {
                for ($i = 1; $i <= $products; $i++) {
                    $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $i, 'type' => 'simple', 'status' => 'publish', 'title' => 'p'.$i, 'image_url' => "https://s.test/{$i}.jpg", 'hash' => 'h'.$i, 'payload' => []]);
                    if ($i <= $scanned) {
                        RetrievalImage::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => (string) $i, 'title' => 'p'.$i, 'image_url' => "https://s.test/{$i}.jpg", 'url_hash' => 'u'.$i, 'embedding_model' => 'gemini-embedding-2', 'embedded_at' => now()]);
                    }
                }
            }

            // A part the worker cut off an hour ago, still marked running.
            Run::query()->forceCreate(['shop_id' => $waiting->id, 'agent' => 'retrieval.image_indexer', 'action' => 'retrieval.build_image_index', 'status' => 'running', 'trigger' => 'manual', 'started_at' => now()->subHour()]);
        });

        Artisan::call('search:resume-pictures');

        Queue::assertPushed(ScanPicturesJob::class, fn (ScanPicturesJob $job): bool => $job->shopId === $waiting->id);
        Queue::assertPushed(ScanPicturesJob::class, 1);
        $this->assertSame('failed', app(TenantContext::class)->runUnscoped(fn () => Run::query()->sole()->status->value), 'the cut-off part is marked so');
    }

    public function test_a_scan_running_now_is_left_alone(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        Features::override('retrieval.image_index', true, $shop->id);
        app(TenantContext::class)->runUnscoped(function () use ($shop): void {
            CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'title' => 'p', 'image_url' => 'https://s.test/1.jpg', 'hash' => 'h', 'payload' => []]);
            Run::query()->forceCreate(['shop_id' => $shop->id, 'agent' => 'retrieval.image_indexer', 'action' => 'retrieval.build_image_index', 'status' => 'running', 'trigger' => 'manual', 'started_at' => now()->subMinutes(3)]);
        });

        Artisan::call('search:resume-pictures');

        Queue::assertNothingPushed();
    }
}
