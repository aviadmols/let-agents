<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\ReadPhotoTags;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

    public function test_only_what_is_close_to_the_best_match_shows_far_from_the_best_does_not(): void
    {
        // Above the fixed floor, but far below the best match: a box of screws next to a drill.
        $this->inShop(fn () => $this->shirt('4', 'חולצה משובצת', [0.8, 0.5, 0.1]));
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $this->assertSame(['1', '2'], array_column($this->upload()->assertOk()->json('groups.product'), 'external_id'));
    }

    public function test_a_photo_brings_other_brands_of_the_same_kind_and_only_what_is_in_stock(): void
    {
        $this->inShop(function (): void {
            $drills = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'c1', 'name' => 'מברגות', 'path' => ['מברגות'], 'depth' => 0, 'hash' => 'c1']);
            $screws = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'c2', 'name' => 'ברגים', 'path' => ['ברגים'], 'depth' => 0, 'hash' => 'c2']);
            $make = function (string $id, string $title, array $vector, string $brand, $category, bool $inStock = true): void {
                $this->shirt($id, $title, $vector);
                $product = CatalogProduct::query()->where('external_id', $id)->sole();
                $product->update(['brand' => $brand, 'in_stock' => $inStock]);
                $product->categories()->attach($category->id);
            };

            $make('11', 'מברגה מקיטה א', [1.0, 0.0, 0.1], 'Makita', $drills);
            $make('12', 'מברגה מקיטה ב', [0.98, 0.05, 0.1], 'Makita', $drills);
            $make('13', 'מברגה מקיטה ג', [0.97, 0.05, 0.12], 'Makita', $drills);
            $make('14', 'מברגה בוש', [0.8, 0.4, 0.1], 'Bosch', $drills);
            $make('15', 'מברגה מקיטה שאזלה', [0.99, 0.02, 0.1], 'Makita', $drills, false);
            $make('16', 'בורג גבס', [0.97, 0.1, 0.05], 'Generic', $screws);
            CatalogProduct::query()->whereIn('external_id', ['1', '2', '3'])->delete();
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $found = array_column($this->upload()->assertOk()->json('groups.product'), 'external_id');

        $this->assertSame(['11', '12', '14', '13', '16'], $found, 'drills of every brand first, never a third Makita in a row while Bosch waits, screws last, the sold-out drill not at all');
    }

    public function test_what_the_photo_shows_comes_back_as_the_shops_own_tags_and_is_never_asked_twice(): void
    {
        $this->inShop(function (): void {
            foreach ([['k1', 'עץ אורן'], ['k2', 'פרגולות'], ['k3', 'ברגים']] as [$id, $name]) {
                CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'name' => $name, 'path' => [$name], 'depth' => 0, 'hash' => $id, 'product_count' => 3]);
            }
            $this->shirt('20', 'דק איפאה לרצפה', [0.0, 0.0, 1.0]);
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $vision = new class implements VisionModel
        {
            public int $calls = 0;

            public string $user = '';

            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls++;
                $this->user = $user;

                // The list is in name order: 1 ברגים, 2 עץ אורן, 3 פרגולות. 9 is not on it.
                return new ModelReply(['picks' => [2, 3, 9, 2], 'seen' => ['דק', 'כיסא מתקפל']], 1800, 40);
            }
        };
        $this->app->instance(VisionModel::class, $vision);

        $tags = $this->upload()->assertOk()->json('tags');

        $this->assertSame([
            ['title' => 'עץ אורן', 'kind' => 'category'],
            ['title' => 'פרגולות', 'kind' => 'category'],
            ['title' => 'דק', 'kind' => 'words'],
        ], array_map(fn (array $t): array => ['title' => $t['title'], 'kind' => $t['kind']], $tags), 'only real categories, and only a seen word the shop has products for');
        $this->assertStringContainsString('2. עץ אורן', $vision->user);

        $this->upload()->assertOk();
        $this->assertSame(1, $vision->calls, 'the same photo is never asked twice');
        $this->assertSame(1, Run::query()->where('agent', ReadPhotoTags::AGENT)->count(), 'the call is accounted for');

        Features::override('search.photo_tags', false, $this->shop->id);
        Cache::flush();
        $this->assertSame([], $this->upload()->assertOk()->json('tags'));
        $this->assertSame(1, $vision->calls, 'off is off');
    }

    public function test_a_photo_of_nothing_the_shop_sells_finds_nothing_and_a_scene_finds_its_tags_products(): void
    {
        $this->inShop(function (): void {
            $pine = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k1', 'name' => 'עץ אורן', 'path' => ['עץ אורן'], 'depth' => 0, 'hash' => 'k1', 'product_count' => 1]);
            $screws = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k2', 'name' => 'ברגים', 'path' => ['ברגים'], 'depth' => 0, 'hash' => 'k2', 'product_count' => 1]);
            // A screw on white scores close to any photo; the beam's picture looks little like a pergola.
            $this->shirt('30', 'בורג גבס', [1.0, 0.0, 0.05]);
            $this->shirt('31', 'קורת עץ אורן', [0.2, 1.0, 0.0]);
            CatalogProduct::query()->where('external_id', '30')->sole()->categories()->attach($screws->id);
            CatalogProduct::query()->where('external_id', '31')->sole()->categories()->attach($pine->id);
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $vision = new class implements VisionModel
        {
            public array $answer = ['picks' => [], 'seen' => []];

            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                return new ModelReply($this->answer, 1800, 20);
            }
        };
        $this->app->instance(VisionModel::class, $vision);

        // A child: the reader sees nothing the shop sells.
        $child = $this->upload()->assertOk()->json();
        $this->assertSame([], $child['groups']['product'], 'no screw for a child');
        $this->assertTrue($child['nothing']);

        // A pergola: the reader picks pine (number 2 in name order: ברגים, עץ אורן).
        Cache::flush();
        $vision->answer = ['picks' => [2], 'seen' => []];
        $scene = $this->upload()->assertOk()->json();
        $this->assertSame(['31'], array_column($scene['groups']['product'], 'external_id'), 'the tag decides what shows; the close screw does not');
        $this->assertSame([0], $scene['groups']['product'][0]['tags'], 'each product says which tags it belongs to');
    }
}
