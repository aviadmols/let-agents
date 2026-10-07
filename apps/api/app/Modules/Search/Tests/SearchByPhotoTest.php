<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A shopper's photo finds the products that look like it: once per photo, within the shop's
 * limits, and only what is really a picture.
 */
final class SearchByPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_cccccccccccccccccccccccccccccccccccccccccccccccc';

    /** A tiny real JPEG, so the server reads its type from the bytes. */
    private const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AN//Z';

    private Shop $shop;

    private string $site;

    private object $pictures;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        Features::override('search.photos', true, $this->shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);

        // Every photo looks like a striped shirt to this fake model.
        $this->pictures = new class implements ImageEmbedder
        {
            public int $photos = 0;

            public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
            {
                $this->photos += count($images);

                return new Embeddings(array_map(fn (): array => [1.0, 0.0, 0.1], $images), 258);
            }

            public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
            {
                return new Embeddings(array_map(fn (): array => [0.0, 1.0, 0.0], $texts), 10);
            }
        };
        $this->app->instance(ImageEmbedder::class, $this->pictures);

        $this->inShop(function (): void {
            $this->shirt('1', 'חולצת פסים כחולה', [1.0, 0.0, 0.0]);
            $this->shirt('2', 'חולצת פסים אדומה', [0.9, 0.1, 0.2]);
            $this->shirt('3', 'מכנסי ג׳ינס', [0.0, 1.0, 0.0]);
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_a_photo_finds_what_looks_like_it_and_the_same_photo_is_free(): void
    {
        $result = $this->upload()->assertOk()->json();

        $this->assertSame(['1', '2'], array_column($result['groups']['product'], 'external_id'), 'the jeans do not look like a striped shirt');
        $this->assertGreaterThanOrEqual(95, $result['groups']['product'][0]['match']);
        $this->assertSame('https://www.store.test/p/1', $result['groups']['product'][0]['url']);

        $this->upload()->assertOk();
        $this->assertSame(1, $this->pictures->photos, 'the same photo again costs nothing');

        $term = $this->inShop(fn () => SearchTerm::query()->sole());
        $this->assertSame(['[photo]', 2, 0], [$term->query, $term->searches, $term->empty], 'counted, the photo never kept');
    }

    public function test_the_camera_shows_only_where_pictures_are_indexed(): void
    {
        $this->getJson("/api/v1/search/{$this->site}/index")->assertJsonPath('config.photos', true);

        Features::override('search.photos', false, $this->shop->id);
        $this->getJson("/api/v1/search/{$this->site}/index")->assertJsonPath('config.photos', false);
        $this->upload()->assertNotFound();

        Features::override('search.photos', true, $this->shop->id);
        $this->inShop(fn () => RetrievalImage::query()->delete());
        $this->getJson("/api/v1/search/{$this->site}/index")->assertJsonPath('config.photos', false);
        $this->upload()->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(0, $this->pictures->photos, 'no pictures to compare with, no photo embedded');
    }

    public function test_only_a_real_picture_within_the_limits_is_searched(): void
    {
        $this->call('POST', "/api/v1/search/{$this->site}/photo", ['photo' => UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo 1;')], server: ['HTTP_ORIGIN' => 'https://www.store.test'])
            ->assertStatus(415);

        Settings::set('search.photo_max_kb', 100);
        $this->call('POST', "/api/v1/search/{$this->site}/photo", ['photo' => UploadedFile::fake()->create('big.jpg', 200, 'image/jpeg')], server: ['HTTP_ORIGIN' => 'https://www.store.test'])
            ->assertStatus(413);

        $this->call('POST', "/api/v1/search/{$this->site}/photo", [], server: ['HTTP_ORIGIN' => 'https://www.store.test'])->assertStatus(422);
        $this->upload('https://evil.test')->assertForbidden();

        Settings::set('search.photos_per_day', 1, $this->shop->id);
        $this->upload()->assertOk();
        $this->upload()->assertStatus(429);
        $this->assertSame(1, $this->pictures->photos);
    }

    private function upload(string $origin = 'https://www.store.test'): TestResponse
    {
        $photo = UploadedFile::fake()->createWithContent('photo.jpg', base64_decode(self::JPEG));

        return $this->call('POST', "/api/v1/search/{$this->site}/photo", ['photo' => $photo], server: ['HTTP_ORIGIN' => $origin, 'HTTP_ACCEPT' => 'application/json']);
    }

    /** @param list<float> $vector */
    private function shirt(string $externalId, string $title, array $vector): void
    {
        $product = CatalogProduct::query()->create([
            'shop_id' => $this->shop->id, 'external_id' => $externalId, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
            'url' => "https://www.store.test/p/{$externalId}", 'image_url' => "https://www.store.test/i/{$externalId}.jpg",
            'in_stock' => true, 'purchasable' => true, 'hash' => "h{$externalId}", 'payload' => [],
        ]);

        RetrievalImage::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'external_id' => $externalId, 'title' => $title,
            'image_url' => $product->image_url, 'url_hash' => hash('sha256', (string) $product->image_url),
            'embedding_model' => 'gemini-embedding-2', 'dimensions' => 3, 'embedding' => json_encode($vector), 'embedded_at' => now(),
        ]);
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
