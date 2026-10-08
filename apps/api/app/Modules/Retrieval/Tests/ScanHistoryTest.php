<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\CurrentShop;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Actions\BuildImageIndex;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One shop's scan log: its scans with what they did, and every picture with its state and why. */
final class ScanHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_sees_the_shops_scans_and_every_picture(): void
    {
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();
        $model = (string) Settings::get('retrieval.image_model');

        app(TenantContext::class)->runUnscoped(function () use ($shop, $other, $model): void {
            $picture = fn (string $shopId, string $id, string $title, array $extra): RetrievalImage => RetrievalImage::query()->create(array_merge([
                'shop_id' => $shopId, 'product_id' => CatalogProduct::query()->create([
                    'shop_id' => $shopId, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'hash' => 'h'.$id, 'payload' => [],
                ])->id, 'external_id' => $id, 'title' => $title,
                'image_url' => "https://shop.test/{$id}.jpg", 'url_hash' => hash('sha256', $id),
            ], $extra));

            $picture($shop->id, '1', 'שולחן נגרים', ['embedding_model' => $model, 'embedded_at' => now(), 'dimensions' => 3]);
            $picture($shop->id, '2', 'מברגה נטענת', []);
            $picture($shop->id, '3', 'בורג עדש', ['error' => 'unreachable']);
            $picture($other->id, '9', 'מוצר של חנות אחרת', []);

            Run::query()->forceCreate([
                'shop_id' => $shop->id, 'agent' => BuildImageIndex::AGENT, 'action' => BuildImageIndex::ACTION,
                'status' => 'succeeded', 'trigger' => 'manual', 'started_at' => now()->subMinutes(5), 'finished_at' => now(),
                'output' => ['stopped' => 'time_budget'],
            ]);
        });

        $this->actingAs(User::factory()->operator()->create());
        session([CurrentShop::SESSION_KEY => $shop->id]);

        $this->get('/operator/retrieval/scans')
            ->assertOk()
            ->assertSee(__('retrieval::ui.scans.kinds.retrieval_image_indexer'))
            ->assertSee(__('retrieval::ui.scans.pictures_about', ['scanned' => 1, 'total' => 3, 'pending' => 1, 'failed' => 1]))
            ->assertSee('שולחן נגרים')
            ->assertSee('מברגה נטענת')
            ->assertSee(__('retrieval::ui.scans.reasons.unreachable'))
            ->assertDontSee('מוצר של חנות אחרת')
            ->assertSee('shop.test · 3');

        $this->get('/operator/retrieval/scans?state=failed')
            ->assertOk()
            ->assertSee('בורג עדש')
            ->assertDontSee('שולחן נגרים');
    }
}
