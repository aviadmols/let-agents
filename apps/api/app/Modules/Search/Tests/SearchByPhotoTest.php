<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Retrieval\Contracts\PictureContent;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Models\Run;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\CheckPhotoSearches;
use App\Modules\Search\Actions\ReadPhotoTags;
use App\Modules\Search\Filament\Operator\Pages\PhotoSearches;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Search\Models\SearchPhotoLesson;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
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
            // Close to the photo, neither the same picture (that is above 0.97).
            $this->shirt('1', 'חולצת פסים כחולה', [1.0, 0.0, 0.4]);
            $this->shirt('2', 'חולצת פסים אדומה', [0.85, 0.1, 0.4]);
            $this->shirt('3', 'מכנסי ג׳ינס', [0.0, 1.0, 0.0]);
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_a_photo_finds_what_looks_like_it_and_the_same_photo_is_free(): void
    {
        $result = $this->upload()->assertOk()->json();

        $this->assertSame(['1', '2'], array_column($result['groups']['product'], 'external_id'), 'the jeans do not look like a striped shirt');
        $this->assertGreaterThanOrEqual(95, $result['groups']['product'][0]['match']);
        $this->assertTrue($result['close'], 'its first truly looks like the photo: the drawer may call it the most alike');
        $this->assertSame('https://www.store.test/p/1', $result['groups']['product'][0]['url']);

        $this->upload()->assertOk();
        $this->assertSame(1, $this->pictures->photos, 'the same photo again costs nothing');
        $this->assertSame(['gemini-embedding-2'], DB::table('ai_usage')->pluck('model')->all(), 'the photo is in the spend ledger under its own model, once');

        $term = $this->inShop(fn () => SearchTerm::query()->sole());
        $this->assertSame(['[photo]', 2, 0], [$term->query, $term->searches, $term->empty], 'counted, the photo never kept');

        // Vans: a plain olive tee's photo showed striped knits at 60% marked "the most alike". Under the store's mark,
        // the nearest are shown as the nearest.
        Settings::set('search.photo_close_look', 0.99, $this->shop->id);
        $this->assertFalse($this->upload()->assertOk()->json('close'), 'not alike enough to be called the most alike');
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

        // No limit while the switch is off (for now); with it on, the daily limit holds.
        Settings::set('search.photos_per_day', 1, $this->shop->id);
        $this->upload()->assertOk();
        $this->upload()->assertOk();

        Features::override('search.photo_limits', true, $this->shop->id);
        Cache::flush();
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

                // No list: what it sees, and code finds the shop's words for it.
                return new ModelReply(['object' => 'פרגולה', 'also' => ['פרגולת עץ'], 'around' => ['עץ אורן', 'דק', 'כיסא מתקפל', 'עץ אורן'], 'sure' => 0.9, 'sellable' => true], 1800, 40);
            }
        };
        $this->app->instance(VisionModel::class, $vision);

        $result = $this->upload()->assertOk()->json();

        $this->assertSame([
            ['title' => 'פרגולות', 'kind' => 'category'],
            ['title' => 'עץ אורן', 'kind' => 'category'],
            ['title' => 'דק', 'kind' => 'words'],
        ], array_map(fn (array $t): array => ['title' => $t['title'], 'kind' => $t['kind']], $result['tags']), 'the object first as the shop names it (פרגולה finds פרגולות), then real categories, then a word the shop has products for');
        $this->assertSame('פרגולה', $result['seen'], 'the shopper is told what was seen');
        $this->assertStringNotContainsString('עץ אורן', $vision->user, 'the reader gets no list to pick from');
        $this->assertStringContainsString("The store's language: Hebrew.", $vision->user);

        $this->upload()->assertOk();
        $this->assertSame(1, $vision->calls, 'the same photo is never asked twice');
        $this->assertSame(1, Run::query()->where('agent', ReadPhotoTags::AGENT)->count(), 'the call is accounted for');

        // an English store's photo is read in English, its titles' language: asked anew, not the Hebrew reading
        $this->shop->forceFill(['content_locale' => 'en'])->save();
        $this->upload()->assertOk();
        $this->assertSame(2, $vision->calls);
        $this->assertStringContainsString("The store's language: English.", $vision->user);
        $this->shop->forceFill(['content_locale' => 'he'])->save();
        $this->upload()->assertOk();
        $this->assertSame(2, $vision->calls, 'each language remembered on its own');

        Features::override('search.photo_tags', false, $this->shop->id);
        Cache::flush();
        $this->assertSame([], $this->upload()->assertOk()->json('tags'));
        $this->assertSame(2, $vision->calls, 'off is off');
    }

    public function test_a_photo_of_nothing_the_shop_sells_finds_nothing_and_a_scene_finds_its_tags_products(): void
    {
        $this->inShop(function (): void {
            $pine = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k1', 'name' => 'עץ אורן', 'path' => ['עץ אורן'], 'depth' => 0, 'hash' => 'k1', 'product_count' => 1]);
            $screws = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k2', 'name' => 'ברגים', 'path' => ['ברגים'], 'depth' => 0, 'hash' => 'k2', 'product_count' => 1]);
            // A screw on white scores close to any photo (close, not the same picture); the beam's picture looks little like a pergola.
            $this->shirt('30', 'בורג גבס', [0.9, 0.0, 0.4]);
            $this->shirt('31', 'קורת עץ אורן', [0.2, 1.0, 0.0]);
            CatalogProduct::query()->where('external_id', '30')->sole()->categories()->attach($screws->id);
            CatalogProduct::query()->where('external_id', '31')->sole()->categories()->attach($pine->id);
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $vision = new class implements VisionModel
        {
            public array $answer = ['object' => 'ילד', 'sure' => 0.95, 'sellable' => false];

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

        // A pergola: nothing in the shop is a pergola, so a second look is taken and changes nothing;
        // the pine seen around it is what the shop has.
        Cache::flush();
        $vision->answer = ['object' => 'פרגולה', 'around' => ['עץ אורן'], 'sure' => 0.8, 'sellable' => true];
        $scene = $this->upload()->assertOk()->json();
        $this->assertSame(['31'], array_column($scene['groups']['product'], 'external_id'), 'the tag decides what shows; the close screw does not');
        $this->assertSame([0], $scene['groups']['product'][0]['tags'], 'each product says which tags it belongs to');
    }

    public function test_a_ring_on_a_hand_is_matched_by_what_tells_it_apart_and_by_the_ring_alone(): void
    {
        $this->inShop(function (): void {
            $rings = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k9', 'name' => 'טבעות זהב', 'path' => ['טבעות זהב'], 'depth' => 0, 'hash' => 'k9', 'product_count' => 2]);
            $this->shirt('70', 'טבעת זהב עם אקוומרין אובלי', [0.9, 0.1, 0.3]);
            $this->shirt('71', 'טבעת זהב עם רובי', [0.9, 0.1, 0.3]);
            foreach (['70', '71'] as $id) {
                CatalogProduct::query()->where('external_id', $id)->sole()->categories()->attach($rings->id);
            }
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->reader(['object' => 'טבעת זהב', 'also' => ['טבעת'], 'details' => ['אבן אובלית בצבע תכלת', 'זהב צהוב'], 'around' => [], 'box' => [0.4, 0.5, 0.6, 0.7], 'sure' => 0.9, 'sellable' => true]);

        // The pictures' descriptions: the aquamarine ring is the one described like the photo.
        $content = new class implements PictureContent
        {
            public string $asked = '';

            public function picturesNearWords(string $shopId, string $text, int $limit): array
            {
                $this->asked = $text;

                return str_contains($text, 'תכלת') ? [['external_id' => '70', 'similarity' => 0.8], ['external_id' => '71', 'similarity' => 0.2]] : [];
            }
        };
        $this->app->instance(PictureContent::class, $content);

        // A real photo, big enough to cut the ring out of it.
        $image = imagecreatetruecolor(200, 260);
        ob_start();
        imagejpeg($image);
        $photo = UploadedFile::fake()->createWithContent('hand.jpg', (string) ob_get_clean());
        $before = $this->pictures->photos;

        $result = $this->call('POST', "/api/v1/search/{$this->site}/photo", ['photo' => $photo], server: ['HTTP_ORIGIN' => 'https://www.store.test', 'HTTP_ACCEPT' => 'application/json'])->assertOk()->json();

        $this->assertSame('טבעת זהב, אבן אובלית בצבע תכלת, זהב צהוב', $content->asked, 'the details, not the category name, are matched with the descriptions');
        $this->assertSame('70', $result['groups']['product'][0]['external_id'], 'the aquamarine ring before the ruby one');
        $this->assertSame(2, $this->pictures->photos - $before, 'the whole photo and the ring cut from it');
        $this->assertSame(['אבן אובלית בצבע תכלת', 'זהב צהוב'], $this->inShop(fn () => SearchPhotoAsk::query()->latest('id')->first()->details), 'the log shows what told it apart');
    }

    public function test_two_things_in_one_photo_are_offered_apart_and_each_is_searched_alone(): void
    {
        $this->inShop(function (): void {
            $rings = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k9', 'name' => 'טבעות', 'path' => ['טבעות'], 'depth' => 0, 'hash' => 'k9', 'product_count' => 1]);
            $bands = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k8', 'name' => 'טבעות נישואין', 'path' => ['טבעות נישואין'], 'depth' => 0, 'hash' => 'k8', 'product_count' => 1]);
            $this->shirt('80', 'טבעת אבן תכלת', [0.9, 0.1, 0.3]);
            $this->shirt('81', 'טבעת נישואין חלקה', [0.9, 0.1, 0.3]);
            CatalogProduct::query()->where('external_id', '80')->sole()->categories()->attach($rings->id);
            CatalogProduct::query()->where('external_id', '81')->sole()->categories()->attach($bands->id);
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $vision = $this->reader([
            'object' => 'טבעת', 'also' => [], 'details' => ['אבן בצבע תכלת'], 'around' => [], 'box' => [0.3, 0.5, 0.5, 0.65],
            'others' => [['object' => 'טבעת נישואין', 'details' => ['פס זהב דק'], 'box' => [0.6, 0.3, 0.66, 0.38]], ['object' => 'טבעת', 'box' => [0.1, 0.1, 0.2, 0.2]]],
            'sure' => 0.9, 'sellable' => true,
        ]);
        $image = imagecreatetruecolor(600, 800);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        $send = fn (int $item) => $this->call('POST', "/api/v1/search/{$this->site}/photo", ['photo' => UploadedFile::fake()->createWithContent('hand.jpg', $bytes), 'item' => $item], server: ['HTTP_ORIGIN' => 'https://www.store.test', 'HTTP_ACCEPT' => 'application/json'])->assertOk()->json();

        $first = $send(0);
        $this->assertSame(['טבעת', 'טבעת נישואין'], $first['items'], 'the two separate things; the object named again is not a third');
        $this->assertSame(0, $first['item']);

        $second = $send(1);
        $this->assertSame(1, $second['item']);
        $this->assertSame('טבעת נישואין · פס זהב דק', $second['seen'], 'what it is, and what tells it apart');
        $this->assertSame('טבעות נישואין', $second['tags'][0]['title'], 'the wedding band is placed among the bands');
        $this->assertSame(1, $vision->calls, 'the photo is read once; choosing the other thing asks nothing again');

        $logged = $this->inShop(fn () => SearchPhotoAsk::query()->latest('id')->first());
        $this->assertSame([1, ['טבעת', 'טבעת נישואין'], true, ['פס זהב דק']], [$logged->item, $logged->items, $logged->cut, $logged->details]);
        $this->assertArrayHasKey('look', $logged->results[0], 'each result says why it is there');
    }

    public function test_the_team_marks_the_right_product_and_the_check_says_where_it_stands_with_and_without_the_second_ordering(): void
    {
        $this->inShop(function (): void {
            $rings = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k9', 'name' => 'טבעות', 'path' => ['טבעות'], 'depth' => 0, 'hash' => 'k9', 'product_count' => 3]);
            // Close to the photo, none the same picture.
            foreach (['90' => [0.9, 0.1, 0.4], '91' => [0.85, 0.15, 0.45], '92' => [0.8, 0.2, 0.5]] as $id => $vector) {
                $this->shirt($id, 'טבעת '.$id, $vector);
                CatalogProduct::query()->where('external_id', $id)->sole()->categories()->attach($rings->id);
            }
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        // The reader says ring; the model that orders sees the sheet and puts 92 first.
        $vision = new class implements VisionModel
        {
            public int $sheets = 0;

            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                if (str_contains($system, 'put them in order') || str_contains($system, 'in order.')) {
                    $this->sheets++;
                    preg_match('/numbered 1 to (\d+)/', $user, $m);

                    return new ModelReply(['order' => [3, 1, 2], 'same' => null], 1700, 20);
                }

                return new ModelReply(['object' => 'טבעת', 'also' => [], 'around' => [], 'sure' => 0.9, 'sellable' => true], 1800, 20);
            }
        };
        $this->app->instance(VisionModel::class, $vision);
        Http::fake(['*' => Http::response(base64_decode(self::JPEG), 200, ['Content-Type' => 'image/jpeg'])]);

        $image = imagecreatetruecolor(400, 400);
        ob_start();
        imagejpeg($image);
        $photo = UploadedFile::fake()->createWithContent('ring.jpg', (string) ob_get_clean());
        $shown = $this->call('POST', "/api/v1/search/{$this->site}/photo", ['photo' => $photo], server: ['HTTP_ORIGIN' => 'https://www.store.test', 'HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
        $this->assertSame('90', $shown['groups']['product'][0]['external_id'], 'the second ordering is off unless the shop turns it on');
        $this->assertSame(0, $vision->sheets);

        // The team says 92 was the right one, by its link.
        $ask = $this->inShop(fn () => SearchPhotoAsk::query()->latest('id')->first());
        $this->actingAs(User::factory()->operator()->create());
        Filament::setCurrentPanel(Filament::getPanel(User::OPERATOR_PANEL));
        Livewire::test(PhotoSearches::class, ['shop' => $this->shop->id])
            ->set("product.{$ask->id}", 'https://www.store.test/p/92')
            ->call('markProduct', $ask->id);
        $this->assertSame('92', $ask->fresh()->expected_product);

        $before = $this->inShop(fn () => SearchPhotoAsk::query()->count());
        $this->assertSame(1, app(CheckPhotoSearches::class)->handle($this->shop->id)['ranked']);
        $this->assertSame([3, 1], [$ask->fresh()->checked_rank, $ask->fresh()->checked_rank_ranked], 'third as it is, first with the second ordering');
        $this->assertSame($before, $this->inShop(fn () => SearchPhotoAsk::query()->count()), 'the check logs no search of its own');
        $this->assertSame(1, $vision->sheets, 'one sheet, one call');

        Livewire::test(PhotoSearches::class, ['shop' => $this->shop->id])
            ->assertSee(__('search::ui.photo_log.ranks', ['count' => 1, 'first' => 0, 'three' => 100, 'five' => 100]))
            ->assertSee(__('search::ui.photo_log.ranks_second', ['first' => 100, 'three' => 100, 'five' => 100]));

        // Turned on for the shop, shoppers get the second order, asked once for the same photo.
        Features::override('search.photo_rank', true, $this->shop->id);
        $again = $this->call('POST', "/api/v1/search/{$this->site}/photo", ['photo' => $photo], server: ['HTTP_ORIGIN' => 'https://www.store.test', 'HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
        $this->assertSame('92', $again['groups']['product'][0]['external_id']);
        $this->assertSame(1, $vision->sheets, 'the same photo and candidates are not asked twice');
    }

    public function test_the_likest_product_of_a_big_category_is_found_wherever_the_database_keeps_it(): void
    {
        $this->inShop(function (): void {
            $rings = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k9', 'name' => 'טבעות', 'path' => ['טבעות'], 'depth' => 0, 'hash' => 'k9', 'product_count' => 131]);
            // 130 rings that look little like the photo, then, last of all, the one that does.
            foreach (range(1, 130) as $i) {
                $this->shirt((string) (1000 + $i), 'טבעת '.$i, [0.2, 1.0, 0.1]);
            }
            $this->shirt('2000', 'טבעת סוליטר', [0.9, 0.1, 0.4]);
            CatalogProduct::query()->whereIn('external_id', [...array_map(fn (int $i): string => (string) (1000 + $i), range(1, 130)), '2000'])->get()
                ->each(fn (CatalogProduct $p) => $p->categories()->attach($rings->id));
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->reader(['object' => 'טבעת', 'also' => [], 'around' => [], 'sure' => 0.9, 'sellable' => true]);

        $result = $this->upload()->assertOk()->json();

        $this->assertSame('2000', $result['groups']['product'][0]['external_id'], 'the whole category is weighed, not its first rows');
    }

    public function test_of_many_products_the_objects_words_name_the_picture_chooses_never_the_first_found(): void
    {
        // Rocks, 26.10: the emerald halo ring, likest by its picture, its words and its colour, came after the 24 other
        // halo rings the words found first
        $this->inShop(function (): void {
            $rings = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k9', 'name' => 'טבעות', 'path' => ['טבעות'], 'depth' => 0, 'hash' => 'k9', 'product_count' => 31]);
            foreach (range(1, 30) as $i) {
                $this->shirt((string) (1000 + $i), 'טבעת הילה דיאנה '.$i, [0.2, 1.0, 0.1]);
            }
            $this->shirt('2000', 'טבעת הילה דיאנה אמרלד', [0.9, 0.1, 0.4]);
            CatalogProduct::query()->whereIn('external_id', [...array_map(fn (int $i): string => (string) (1000 + $i), range(1, 30)), '2000'])->get()
                ->each(fn (CatalogProduct $p) => $p->categories()->attach($rings->id));
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->reader(['object' => 'טבעת הילה', 'also' => ['טבעת'], 'details' => [], 'around' => [], 'sure' => 0.9, 'sellable' => true]);

        $result = $this->upload()->assertOk()->json();

        $this->assertSame('2000', $result['groups']['product'][0]['external_id'], 'all the halo rings weighed by their pictures');
    }

    public function test_a_product_named_with_the_words_seen_in_the_photo_comes_before_a_lookalike(): void
    {
        $this->inShop(function (): void {
            $rings = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k9', 'name' => 'טבעות', 'path' => ['טבעות'], 'depth' => 0, 'hash' => 'k9', 'product_count' => 3]);
            // Pictures equally alike; only the names differ.
            $this->shirt('95', 'טבעת וינטג׳ משובצת לאפיס לזולי, זהב', [0.9, 0.1, 0.4]);
            $this->shirt('96', 'טבעת פס חלקה דקה, זהב', [0.9, 0.1, 0.4]);
            $this->shirt('97', 'טבעת אבן ירקרקה, זהב', [0.9, 0.1, 0.4]);
            CatalogProduct::query()->whereIn('external_id', ['95', '96', '97'])->get()->each(fn (CatalogProduct $p) => $p->categories()->attach($rings->id));
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->reader(['object' => 'טבעת פס חלקה', 'also' => ['טבעת'], 'details' => ['פס זהב דק'], 'around' => [], 'sure' => 0.9, 'sellable' => true]);

        $result = $this->upload()->assertOk()->json();

        $this->assertSame('96', $result['groups']['product'][0]['external_id'], 'the plain thin band, by its name; gold, which every ring is, decides nothing');
        $this->assertGreaterThan(0, $result['groups']['product'][0]['words']);
    }

    public function test_a_plain_band_puts_rings_with_stones_after_the_rest(): void
    {
        $this->inShop(function (): void {
            $rings = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k9', 'name' => 'טבעות', 'path' => ['טבעות'], 'depth' => 0, 'hash' => 'k9', 'product_count' => 2]);
            // The ring with diamonds looks a little closer; the plain one has none.
            $this->shirt('98', 'טבעת רחבה ארבעה פסים משובצים יהלומים', [0.9, 0.1, 0.4]);
            $this->shirt('99', 'טבעת זהב פרופיל חצי עגול', [0.85, 0.15, 0.45]);
            CatalogProduct::query()->whereIn('external_id', ['98', '99'])->get()->each(fn (CatalogProduct $p) => $p->categories()->attach($rings->id));
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->reader(['object' => 'טבעת פס חלקה', 'also' => ['טבעת'], 'details' => ['פס זהב דק'], 'lacks' => ['אבן', 'יהלומים', 'משובצת'], 'around' => [], 'sure' => 0.9, 'sellable' => true]);

        $products = $this->upload()->assertOk()->json('groups.product');

        $this->assertSame(['99', '98'], array_column($products, 'external_id'), 'what the band plainly lacks puts the diamond ring after it');
        $this->assertTrue($products[1]['lacks']);
    }

    public function test_a_photo_the_reader_could_not_make_out_says_so_instead_of_showing_lookalikes(): void
    {
        // The model answered, but its answer was cut off: not JSON.
        $this->app->instance(VisionModel::class, new class implements VisionModel
        {
            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                throw new ModelCallFailed(ModelCallFailed::NOT_JSON);
            }
        });

        $result = $this->upload()->assertOk()->json();

        $this->assertTrue($result['unclear']);
        $this->assertSame([], $result['groups']['product'], 'no shirts that only share the photo\'s colours');
    }

    public function test_what_the_shopper_photographed_comes_first_whatever_was_scanned(): void
    {
        $this->inShop(function (): void {
            $drills = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k1', 'name' => 'מברגות', 'path' => ['מברגות'], 'depth' => 0, 'hash' => 'k1', 'product_count' => 1]);
            $boxes = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k2', 'name' => 'ארגזי כלים', 'path' => ['ארגזי כלים'], 'depth' => 0, 'hash' => 'k2', 'product_count' => 1]);
            // The tool box's picture is scanned and close (not the same picture); the drill's is not scanned yet.
            $this->shirt('40', 'ארגז כלים', [0.85, 0.1, 0.35]);
            CatalogProduct::query()->where('external_id', '40')->sole()->categories()->attach($boxes->id);
            $drill = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '41', 'type' => 'simple', 'status' => 'publish', 'title' => 'מברגה נטענת', 'url' => 'https://www.store.test/p/41', 'in_stock' => true, 'purchasable' => true, 'hash' => 'h41', 'payload' => []]);
            $drill->categories()->attach($drills->id);
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $this->app->instance(VisionModel::class, new class implements VisionModel
        {
            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                // The drill is the main object; the tool box is around it.
                return new ModelReply(['object' => 'מברגה', 'around' => ['ארגז כלים'], 'sure' => 0.9, 'sellable' => true], 1800, 20);
            }
        });

        $result = $this->upload()->assertOk()->json();

        $this->assertSame('מברגות', $result['tags'][0]['title'], 'the main object leads the tags');
        $this->assertSame(['41', '40'], array_column($result['groups']['product'], 'external_id'), 'the drill before the closer-scoring tool box');
    }

    public function test_a_screwdrivers_photo_shows_only_screwdrivers_however_alike_yellow_snips_look(): void
    {
        // Gueta: a yellow-handled screwdriver brought yellow tin snips and a drill first, by their looks.
        $this->inShop(function (): void {
            $drivers = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k1', 'name' => 'מברגים', 'path' => ['מברגים'], 'depth' => 0, 'hash' => 'k1', 'product_count' => 3]);
            $snips = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k2', 'name' => 'מספריים', 'path' => ['מספריים'], 'depth' => 0, 'hash' => 'k2', 'product_count' => 1]);
            $this->shirt('60', 'מספרי פח צהובים', [0.85, 0.1, 0.35]);
            CatalogProduct::query()->where('external_id', '60')->sole()->categories()->attach($snips->id);
            foreach (['61' => 'מברג פיליפס ידית גומי', '62' => 'מברג שטוח ארוך', '63' => 'מברג מבודד 1000V'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []])
                    ->categories()->attach($drivers->id);
            }
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->reader(['object' => 'מברג', 'main' => 'מברגים', 'around' => ['מספריים'], 'sure' => 0.9, 'sellable' => true]);

        $ids = array_column($this->upload()->assertOk()->json('groups.product'), 'external_id');

        $this->assertEqualsCanonicalizing(['61', '62', '63'], $ids, 'only the screwdrivers: the snips look alike and do something else');
    }

    public function test_what_the_reader_calls_the_object_comes_first_whatever_category_it_chose(): void
    {
        // Gueta: a photo of a bit set was read as "סט ביטים", but the category chosen was "סטים",
        // the power-tool kits, by the shared word; the bit sets are in "ביטים" and never showed.
        $this->inShop(function (): void {
            $kits = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k1', 'name' => 'סטים', 'path' => ['סטים'], 'depth' => 0, 'hash' => 'k1', 'product_count' => 2]);
            $bits = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'k2', 'name' => 'ביטים', 'path' => ['ביטים'], 'depth' => 0, 'hash' => 'k2', 'product_count' => 2]);
            foreach (['50' => ['מולטיטול 18 וולט עם 2 סוללות', $kits], '51' => ['סט מקדחי SDS ואיזמלים במזוודה', $kits], '52' => ['סט ביטים 37 חלקים WIDER', $bits], '53' => ['סט ביטים IMPAKTOR WERA', $bits]] as $id => [$title, $category]) {
                CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []])
                    ->categories()->attach($category->id);
            }
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->reader(['object' => 'סט ביטים לבורג בבסיס פלסטיק אדום', 'main' => 'סטים', 'details' => ['בסיס פלסטיק אדום', 'ביטים מתכת כסופים'], 'around' => [], 'sure' => 0.8, 'sellable' => true]);

        $result = $this->upload()->assertOk()->json();

        $this->assertEqualsCanonicalizing(['52', '53'], array_slice(array_column($result['groups']['product'], 'external_id'), 0, 2), 'the bit sets first');
        $this->assertSame([true, true], array_slice(array_column($result['groups']['product'], 'kind'), 0, 2), 'and of the kind photographed');
    }

    public function test_a_tap_is_placed_among_taps_never_iron_and_the_pictures_can_demand_a_second_look(): void
    {
        $taps = $this->shopWithTapsHandlesAndIron();
        $vision = $this->reader(
            ['object' => 'ברז מטבח', 'also' => ['ברז'], 'around' => [], 'sure' => 0.9, 'sellable' => true],
            ['main' => null, 'object' => 'ברז מטבח', 'sure' => 0.9, 'sellable' => true],
        );

        // Placed by words: ברז is in ברזים and in ברזי מטבח, not in ברזל. Nothing to doubt yet:
        // the tap's own picture is among the nearest.
        $result = $this->upload()->assertOk()->json();
        $this->assertSame('ברזי מטבח', $result['tags'][0]['title'], 'the category holding most of the object\'s words, not the iron');
        $this->assertSame('50', $result['groups']['product'][0]['external_id']);
        $this->assertSame(1, $vision->calls, 'no second look when the words and the pictures agree');

        // Now the tap's picture is not scanned, and every near picture is a handle: the pictures
        // disagree with the reading, so a stronger model takes a second look with a short list.
        $this->inShop(fn () => RetrievalImage::query()->where('external_id', '50')->delete());
        Cache::flush();
        $vision->second = ['main' => 'ברזים', 'object' => 'ברז', 'sure' => 0.95, 'sellable' => true];
        $result = $this->upload()->assertOk()->json();

        $this->assertSame(3, $vision->calls, 'the first look again (the cache was cleared), then a second look');
        $this->assertStringContainsString('ידיות', $vision->user, 'the list holds what the pictures say');
        $this->assertStringContainsString('ברזי מטבח', $vision->user, 'and where the words placed it');
        $this->assertStringContainsString('pictures_disagree', $vision->user);
        $this->assertSame('ברזים', $result['tags'][0]['title'], 'the second look\'s category leads');
        $this->assertSame('ברז מטבח', $result['seen'], 'the second look said tap, the kind the first already named: the fuller words stay');
        $this->assertSame(1, Run::query()->where('action', ReadPhotoTags::SECOND_ACTION)->count(), 'the second look is accounted for on its own');

        $ask = $this->inShop(fn () => SearchPhotoAsk::query()->latest('id')->first());
        $this->assertSame(['ברז מטבח', 'ברזים', 'pictures_disagree', true], [$ask->object, $ask->main, $ask->doubt, $ask->second], 'the log says how the tags were reached');

        // The same photo again: both looks come from cache.
        $this->upload()->assertOk();
        $this->assertSame(3, $vision->calls);
    }

    public function test_an_unsure_reading_gets_a_second_look_that_corrects_the_object(): void
    {
        $this->shopWithTapsHandlesAndIron();
        $vision = $this->reader(
            ['object' => 'ידית', 'also' => [], 'around' => [], 'sure' => 0.3, 'sellable' => true],
            ['main' => null, 'object' => 'ברז', 'sure' => 0.9, 'sellable' => true],
        );

        $result = $this->upload()->assertOk()->json();

        $this->assertSame(2, $vision->calls, 'low confidence asks for a second look');
        $this->assertStringContainsString('unsure', $vision->user);
        $this->assertSame('ברזים', $result['tags'][0]['title'], 'the corrected object is placed again');
        $this->assertSame('ברז', $result['seen']);

        // With the second look off, the first reading stands, unsure as it is.
        Features::override('search.photo_second_look', false, $this->shop->id);
        Cache::flush();
        $this->assertSame('ידיות', $this->upload()->assertOk()->json('tags.0.title'));
        $this->assertSame(3, $vision->calls);
    }

    public function test_the_team_sees_each_photo_search_marks_it_and_measures_the_reader_against_the_marks(): void
    {
        $this->shopWithTapsHandlesAndIron();
        $this->reader(['object' => 'ידית', 'also' => [], 'around' => [], 'sure' => 0.9, 'sellable' => true]);
        $this->upload()->assertOk();

        $ask = $this->inShop(fn () => SearchPhotoAsk::query()->sole());
        $this->assertSame(['ידית', 'ידיות', null], [$ask->object, $ask->main, $ask->verdict]);
        $this->assertNotNull($ask->thumb, 'a reduced copy is kept');
        $this->assertNotNull($ask->photoBytes());
        $this->assertSame('50', $ask->results[0]['external_id'], 'the tap, whose own picture this is, before the handles the reader named');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        $page = Livewire::test(PhotoSearches::class, ['shop' => $this->shop->id])
            ->assertSee('ידית')->assertSee('ידיות')
            ->call('markWrong', $ask->id)
            ->assertSet("expected.{$ask->id}", null);
        $this->assertSame('wrong', $ask->fresh()->verdict, 'wrong needs no word; the stronger model looks again');

        $page->set("expected.{$ask->id}", 'ברז')->call('markWrong', $ask->id);
        $this->assertSame(['wrong', 'ברז'], [$ask->fresh()->verdict, $ask->fresh()->expected]);

        // Marked wrong, the stronger model looked again with the team's word, and the lesson stands:
        // the reader still says handle, but the same photo is now answered from what was learned.
        $this->assertSame(['ידית', 'ברז', 'ברז'], $this->inShop(fn () => SearchPhotoLesson::query()->sole()->only(['seen', 'team', 'object'])) ? array_values($this->inShop(fn () => SearchPhotoLesson::query()->sole()->only(['seen', 'team', 'object']))) : []);
        Cache::flush();
        $this->assertSame(['checked' => 1, 'right' => 1, 'ranked' => 0], app(CheckPhotoSearches::class)->handle($this->shop->id));
        $this->assertSame(['ברזים', true], [$ask->fresh()->checked_main, $ask->fresh()->checked_right]);
        Livewire::test(PhotoSearches::class, ['shop' => $this->shop->id])->assertSee('1 מתוך 1');

        // An unmarked search keeps what it learned after its days, without its full photo; a marked
        // one keeps the photo too, as the check set.
        $old = $this->inShop(fn () => SearchPhotoAsk::query()->create(['shop_id' => $this->shop->id, 'photo_hash' => 'old', 'photo' => 'data:image/jpeg;base64,AAAA', 'thumb' => 'data:image/jpeg;base64,BB', 'object' => 'ברז', 'tags' => ['ברז'], 'results' => [], 'total' => 0, 'created_at' => now()->subDays(10)]));
        Settings::set('search.photo_keep_days', 7);
        Artisan::call('search', ['step' => 'prune']);
        $this->assertEqualsCanonicalizing([$ask->id, $old->id], $this->inShop(fn () => SearchPhotoAsk::query()->pluck('id')->all()), 'what shoppers looked for stays, for learning');
        $kept = $this->inShop(fn () => $old->fresh());
        $this->assertSame([null, 'data:image/jpeg;base64,BB', 'ברז', ['ברז']], [$kept->photo, $kept->thumb, $kept->object, $kept->tags]);
        $this->assertNotNull($this->inShop(fn () => $ask->fresh()->photo), 'the check set keeps its photo');

        // With the log off, nothing is kept.
        Features::override('search.photo_log', false, $this->shop->id);
        $this->upload()->assertOk();
        $this->assertSame(2, $this->inShop(fn () => SearchPhotoAsk::query()->count()));
    }

    /**
     * A reader whose first look is fixed and whose second look (when one is taken) is $second;
     * the second look's category is named, and turned into its number on the list it gets.
     *
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>|null  $second
     */
    private function reader(array $first, ?array $second = null): object
    {
        $vision = new class($first, $second) implements VisionModel
        {
            public int $calls = 0;

            public string $user = '';

            public function __construct(public array $first, public ?array $second) {}

            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls++;
                $this->user = $user;

                if (! str_contains($system, 'second look')) {
                    return new ModelReply($this->first, 1800, 40);
                }

                $answer = $this->second ?? ['main' => null, 'object' => null, 'sure' => 0.5, 'sellable' => true];

                if (is_string($answer['main'])) {
                    preg_match('/^(\d+)\. '.preg_quote($answer['main'], '/').' \(/mu', $user, $m);
                    $answer['main'] = isset($m[1]) ? (int) $m[1] : null;
                }

                return new ModelReply($answer, 2200, 40);
            }
        };
        $this->app->instance(VisionModel::class, $vision);

        return $vision;
    }

    /** Taps, handles and iron: a tap photo must land among the taps. Every handle's picture is near the photo. */
    private function shopWithTapsHandlesAndIron(): CatalogCategory
    {
        $taps = null;
        $this->inShop(function () use (&$taps): void {
            CatalogProduct::query()->whereIn('external_id', ['1', '2', '3'])->delete();
            $category = fn (string $id, string $name, int $count): CatalogCategory => CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'name' => $name, 'path' => [$name], 'depth' => 0, 'hash' => $id, 'product_count' => $count]);
            $taps = $category('k1', 'ברזים', 2);
            $kitchen = $category('k2', 'ברזי מטבח', 1);
            $handles = $category('k3', 'ידיות', 7);
            $iron = $category('k4', 'ברזל', 1);
            $make = function (string $id, string $title, ?array $vector, array $categories): void {
                if ($vector !== null) {
                    $this->shirt($id, $title, $vector);
                } else {
                    CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://www.store.test/p/{$id}", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                }
                $product = CatalogProduct::query()->where('external_id', $id)->sole();
                foreach ($categories as $c) {
                    $product->categories()->attach($c->id);
                }
            };
            $make('50', 'ברז מטבח נשלף כרום', [1.0, 0.0, 0.1], [$taps, $kitchen]);
            $make('52', 'פרופיל ברזל מגולוון', [0.0, 1.0, 0.0], [$iron]);
            $make('53', 'ברז גן', null, [$taps]);
            foreach (range(1, 7) as $i) {
                $make('6'.$i, 'ידית ארון '.$i, [0.99, 0.0, 0.1 + $i / 100], [$handles]);
            }
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        return $taps;
    }

    public function test_the_shop_manager_sees_the_photo_searches_of_their_own_site_without_the_mechanics(): void
    {
        $this->shopWithTapsHandlesAndIron();
        $this->reader(['object' => 'ידית', 'also' => [], 'around' => [], 'sure' => 0.3, 'sellable' => true], ['main' => null, 'object' => 'ידית', 'sure' => 0.9, 'sellable' => true]);
        $this->upload()->assertOk();

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $this->actingAs($merchant)->get("/merchant/{$this->shop->slug}/search/photos")
            ->assertOk()
            ->assertSee('ידית')
            ->assertSee(__('search::ui.photo_log.right'))
            ->assertDontSee(__('search::ui.photo_log.check_now'))
            ->assertDontSee(__('search::ui.photo_log.doubt.unsure'), 'why a second look was taken is the operator\x27s');
    }

    public function test_the_shops_own_picture_uploaded_again_is_that_product_first_whatever_the_reader_said(): void
    {
        $this->shopWithTapsHandlesAndIron();
        // The reader calls the tap a handle; the photo is the tap's own picture (its vector exactly).
        $this->reader(['object' => 'ידית', 'also' => [], 'around' => [], 'sure' => 0.9, 'sellable' => true]);

        $result = $this->upload()->assertOk()->json();

        $this->assertSame('50', $result['groups']['product'][0]['external_id'], 'the product whose picture this is');
        $this->assertTrue($result['groups']['product'][0]['same']);
        $this->assertGreaterThanOrEqual(99, $result['groups']['product'][0]['match']);
        $this->assertSame('61', $result['groups']['product'][1]['external_id'], 'then what the tags chose');
    }

    public function test_the_shop_manager_marks_a_photo_search_from_their_panel(): void
    {
        $this->shopWithTapsHandlesAndIron();
        $this->reader(['object' => 'ידית', 'also' => [], 'around' => [], 'sure' => 0.9, 'sellable' => true]);
        $this->upload()->assertOk();
        $ask = $this->inShop(fn () => SearchPhotoAsk::query()->sole());

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $this->actingAs($merchant);
        Filament::setCurrentPanel(Filament::getPanel('merchant'));
        Filament::setTenant($this->shop);

        Livewire::test(\App\Modules\Search\Filament\Merchant\Pages\PhotoSearches::class)->call('markRight', $ask->id);
        $this->assertSame('right', $ask->fresh()->verdict);
    }
}
