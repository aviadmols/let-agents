<?php

namespace App\Modules\Retrieval\Tests;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Contracts\ImageEmbedder;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Actions\BuildImageIndex;
use App\Modules\Retrieval\Candidates\LookAlike;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Support\MatchCheck;
use App\Modules\Retrieval\Tests\Concerns\BuildsShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each product's main picture as a vector: embedded once per address, refused when it is not a
 * picture or too big, and then compared in code to find products that look alike and pictures
 * that match words.
 */
final class ImageIndexTest extends TestCase
{
    use BuildsShop;
    use RefreshDatabase;

    /** A fake picture model: the "picture" bytes name a colour and a pattern, words do too. */
    private object $pictures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        $this->pictures = new class implements ImageEmbedder
        {
            public int $images = 0;

            public int $texts = 0;

            public ?string $model = null;

            public function embedImages(AiProviderName $provider, string $model, array $images, ?int $dimensions = null): Embeddings
            {
                $this->images += count($images);
                $this->model = $model;

                return new Embeddings(array_map(fn (array $image): array => self::vector($image['data']), $images), count($images) * 258);
            }

            public function embedTextsForImages(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null, bool $query = true): Embeddings
            {
                $this->texts += count($texts);

                return new Embeddings(array_map(fn (string $text): array => self::vector($text), $texts), 10);
            }

            /** @return list<float> */
            public static function vector(string $text): array
            {
                $axes = ['פסים', 'כיס', 'אפור', 'כחול', 'אדום'];

                return array_map(fn (string $axis): float => str_contains($text, $axis) ? 1.0 : 0.05, $axes);
            }
        };
        $this->app->instance(ImageEmbedder::class, $this->pictures);

        $this->shirt('1', 'חולצה A', 'https://store.test/img/1.jpg');
        $this->shirt('2', 'חולצה B', 'https://store.test/img/2.jpg');
        $this->shirt('3', 'חולצה C', 'https://store.test/img/3.jpg');
        $this->shirt('4', 'חולצה D', 'https://store.test/img/4.png');
        $this->shirt('5', 'חולצה E', 'ftp://store.test/img/5.jpg');

        Http::fake([
            'store.test/img/1.jpg' => Http::response('פסים כחול', 200, ['Content-Type' => 'image/jpeg']),
            'store.test/img/2.jpg' => Http::response('פסים כחול', 200, ['Content-Type' => 'image/jpeg']),
            'store.test/img/3.jpg' => Http::response('כיס אפור', 200, ['Content-Type' => 'image/jpeg; charset=binary']),
            'store.test/img/4.png' => Http::response('<html>not found</html>', 200, ['Content-Type' => 'text/html']),
            'store.test/img/*' => Http::response('פסים אדום', 200, ['Content-Type' => 'image/webp']),
        ]);
    }

    public function test_each_picture_is_embedded_once_and_bad_ones_are_marked(): void
    {
        $run = app(BuildImageIndex::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertSame(4, $run->output['products'], 'an ftp address is not a picture the store serves');
        $this->assertSame(3, $run->output['embedded']);
        $this->assertSame(1, $run->output['skipped']);
        $this->assertSame('gemini-embedding-2', $this->pictures->model, 'the model the settings name');
        $this->assertEqualsWithDelta(3 * 0.00012, (float) $run->cost_usd, 0.000001, 'priced per picture');
        $this->assertSame('not_image', $this->inShop(fn () => RetrievalImage::query()->where('external_id', '4')->value('error')));

        app(BuildImageIndex::class)->handle($this->shop->id);
        $this->assertSame(3, $this->pictures->images, 'unchanged addresses are not sent again');

        $this->inShop(fn () => CatalogProduct::query()->where('external_id', '1')->update(['image_url' => 'https://store.test/img/1-new.jpg']));
        $this->inShop(fn () => CatalogProduct::query()->where('external_id', '3')->update(['removed_at' => now()]));
        $again = app(BuildImageIndex::class)->handle($this->shop->id);

        $this->assertSame(4, $this->pictures->images, 'a new picture is embedded');
        $this->assertSame(1, $again->output['removed'], 'a product the store stopped publishing takes its row with it');
    }

    public function test_a_picture_too_big_is_skipped(): void
    {
        // Fakes registered first win, so the big pictures live at an address nothing else answers.
        Settings::set('retrieval.image_max_kb', 100);
        $this->inShop(fn () => CatalogProduct::query()->where('image_url', 'like', 'https://%')->update(['image_url' => 'https://big.test/huge.jpg']));
        Http::fake(['big.test/*' => Http::response(str_repeat('x', 200 * 1024), 200, ['Content-Type' => 'image/jpeg'])]);

        $run = app(BuildImageIndex::class)->handle($this->shop->id);

        $this->assertSame(0, $run->output['embedded']);
        $this->assertSame(['too_big'], $this->inShop(fn () => RetrievalImage::query()->distinct()->pluck('error')->all()));
    }

    public function test_products_that_look_alike_and_pictures_that_match_words(): void
    {
        app(BuildImageIndex::class)->handle($this->shop->id);
        $ids = $this->inShop(fn () => CatalogProduct::query()->pluck('id', 'external_id'));
        $search = app(SemanticSearch::class);

        $alike = $this->inShop(fn () => $search->lookAlike($ids['1'], 3));
        $this->assertSame('2', $alike[0]['external_id'], 'the other blue striped shirt');
        $this->assertGreaterThan(0.99, $alike[0]['similarity']);
        $this->assertNotContains('1', array_column($alike, 'external_id'), 'never itself');

        $byWords = $this->inShop(fn () => $search->picturesNearText($this->shop->id, 'חולצה עם כיס אפור', 2));
        $this->assertSame('3', $byWords[0]['external_id']);
        $this->inShop(fn () => $search->picturesNearText($this->shop->id, 'חולצה עם כיס אפור', 2));
        $this->assertSame(1, $this->pictures->texts, 'the same words cost nothing the second time');

        $candidates = $this->inShop(fn () => app(LookAlike::class)->candidates(CatalogProduct::query()->find($ids['1']), 5));
        $this->assertSame([$ids['2']], array_map(fn ($c) => $c->productId, $candidates), 'only what clears the likeness floor');
        $this->assertSame('looks_alike', $candidates[0]->source);
        $this->assertGreaterThan(0.9, MatchCheck::likeness($candidates[0]->signals), 'a look-alike counts as an alternative');
    }

    public function test_nothing_is_compared_until_the_pictures_are_indexed(): void
    {
        $search = app(SemanticSearch::class);

        $this->assertFalse($this->inShop(fn () => $search->picturesReady()));
        $this->assertSame([], $this->inShop(fn () => $search->picturesNearText($this->shop->id, 'פסים', 3)));
        $this->assertSame(0, $this->pictures->texts, 'no words are embedded for a shop without pictures');
    }

    private function shirt(string $externalId, string $title, string $image): CatalogProduct
    {
        return $this->product($externalId, $title, 'חולצת כותנה.', ['image_url' => $image]);
    }

    public function test_one_scan_of_a_shop_at_a_time(): void
    {
        $held = Cache::lock('retrieval:images:'.$this->shop->id, 60);
        $held->get();

        $run = app(BuildImageIndex::class)->handle($this->shop->id);

        $this->assertSame('already_running', $run->output['stopped']);
        $this->assertSame(0, $this->pictures->images, 'nothing fetched or paid for twice');

        $held->release();
        $this->assertGreaterThan(0, app(BuildImageIndex::class)->handle($this->shop->id)->output['embedded']);
    }
}
