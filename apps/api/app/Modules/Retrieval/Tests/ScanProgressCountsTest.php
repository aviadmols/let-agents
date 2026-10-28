<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Support\ModelChoice;
use App\Modules\Retrieval\Support\PictureTrim;
use App\Modules\Retrieval\Support\RetrievalScanProgress;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pictures step of the scan, as it goes: a picture looked at under the current preparation counts, one that
 * could not be read counts as gone through, one looked at under the old preparation is still to do.
 */
final class ScanProgressCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pictures_count_as_they_are_looked_at_under_the_current_preparation(): void
    {
        $shop = Shop::factory()->create();
        Features::override('retrieval.image_index', true, $shop->id);
        Features::override('retrieval.image_captions', false, $shop->id);

        $progress = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            $model = ModelChoice::for('image')->model;
            foreach ([1 => ['prep' => PictureTrim::VERSION, 'error' => null], 2 => ['prep' => 1, 'error' => null], 3 => ['prep' => null, 'error' => 'too_big']] as $i => $row) {
                $product = CatalogProduct::query()->create([
                    'shop_id' => $shop->id, 'external_id' => (string) $i, 'type' => 'simple', 'status' => 'publish', 'title' => 'P'.$i,
                    'url' => "https://s.test/p/{$i}", 'image_url' => "https://s.test/{$i}.jpg", 'price' => 10, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$i, 'payload' => [],
                ]);
                RetrievalImage::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => (string) $i, 'title' => 'P'.$i,
                    'image_url' => "https://s.test/{$i}.jpg", 'url_hash' => 'u'.$i, 'embedding_model' => $row['error'] ? null : $model,
                    'embedded_at' => $row['error'] ? null : now(), 'prep' => $row['prep'], 'error' => $row['error']]);
            }

            return app(RetrievalScanProgress::class)->scanProgress($shop, []);
        });

        $this->assertSame(['done' => 2, 'total' => 3], $progress['pictures']);
        $this->assertArrayNotHasKey('pages', $progress, 'a step not begun has no count of its own');
    }
}
