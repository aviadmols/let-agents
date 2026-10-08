<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Actions\CaptionImages;
use App\Modules\Retrieval\Contracts\PictureContent;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Every scanned picture gets a second vector: what it shows, in words, embedded as text. */
final class CaptionImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_picture_is_described_once_and_found_by_what_it_shows(): void
    {
        $shop = Shop::factory()->create();
        Http::fake(['*' => Http::response('jpegbytes', 200, ['Content-Type' => 'image/jpeg'])]);

        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'מקדחה', '2' => 'ארגז'] as $id => $title) {
                $product = CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'image_url' => "https://s.test/{$id}.jpg", 'hash' => 'h'.$id, 'payload' => []]);
                RetrievalImage::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => $id, 'title' => $title, 'image_url' => "https://s.test/{$id}.jpg", 'url_hash' => 'u'.$id, 'embedding_model' => 'gemini-embedding-2', 'embedded_at' => now()]);
            }
        });

        $vision = new class implements VisionModel
        {
            public int $calls = 0;

            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls++;

                return new ModelReply($this->calls === 1
                    ? ['caption' => 'מברגה נטענת כחולה עם סוללה', 'words' => ['מברגה', 'סוללה']]
                    : ['caption' => 'ארגז כלים מפלסטיק שחור', 'words' => ['ארגז כלים']], 1600, 40);
            }
        };
        $embedder = new class implements Embedder
        {
            public int $calls = 0;

            public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                $this->calls++;

                // A drill text points one way, a tool box another.
                return new Embeddings(array_map(fn (string $t): array => str_contains($t, 'מברג') ? [1.0, 0.0] : [0.0, 1.0], array_values($texts)), 30);
            }
        };
        $this->app->instance(VisionModel::class, $vision);
        $this->app->instance(Embedder::class, $embedder);

        $run = app(CaptionImages::class)->handle($shop->id);
        $this->assertSame('succeeded', $run->status->value, (string) $run->error);

        $drill = app(TenantContext::class)->run($shop->id, fn () => RetrievalImage::query()->where('external_id', '1')->sole());
        $this->assertSame('מברגה נטענת כחולה עם סוללה', $drill->caption);
        $this->assertSame(['מברגה', 'סוללה'], $drill->caption_words);
        $this->assertNotNull($drill->captioned_at);
        $this->assertSame(3, DB::table('ai_usage')->count(), 'two descriptions and one embedding call, each accounted');

        app(CaptionImages::class)->handle($shop->id);
        $this->assertSame(2, $vision->calls, 'a picture is described once');

        $near = app(TenantContext::class)->run($shop->id, fn () => app(PictureContent::class)->picturesNearWords($shop->id, 'מברגה', 5));
        $this->assertSame('1', $near[0]['external_id'], 'the drill is found by what its picture shows');
    }
}
