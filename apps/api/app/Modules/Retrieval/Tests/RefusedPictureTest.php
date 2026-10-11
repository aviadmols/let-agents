<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Actions\BuildImageIndex;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One picture the provider will not read never stops the scan of the rest. */
final class RefusedPictureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refused_picture_is_marked_and_the_others_get_their_vectors(): void
    {
        $shop = Shop::factory()->create();
        Features::override('retrieval.image_index', true, $shop->id);
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), 'bad') ? 'BAD' : 'GOOD', 200, ['Content-Type' => 'image/jpeg']));

        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'good-1', '2' => 'bad', '3' => 'good-3'] as $id => $name) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => 'p'.$id, 'image_url' => "https://s.test/{$name}.jpg", 'hash' => 'h'.$id, 'payload' => []]);
            }
        });

        $this->app->instance(ImageEmbedder::class, new class implements ImageEmbedder
        {
            public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
            {
                foreach ($images as $image) {
                    if (str_contains((string) ($image['data'] ?? ''), 'BAD')) {
                        throw new ModelCallFailed(ModelCallFailed::PROVIDER_ERROR);
                    }
                }

                return new Embeddings(array_map(fn (): array => [1.0, 0.0], $images), 10);
            }

            public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
            {
                return new Embeddings([], 0);
            }
        });

        app(BuildImageIndex::class)->handle($shop->id);

        $rows = app(TenantContext::class)->run($shop->id, fn () => RetrievalImage::query()->orderBy('external_id')->get()->keyBy('external_id'));
        $this->assertNotNull($rows['1']->embedded_at);
        $this->assertNotNull($rows['3']->embedded_at, 'the picture after the refused one still gets its vector');
        $this->assertSame('refused', $rows['2']->error);
    }
}
