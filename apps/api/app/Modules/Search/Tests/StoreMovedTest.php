<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Connections\Events\StoreAddressMoved;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\FollowShopDomain;
use App\Modules\Retrieval\Actions\CaptionImages;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store that moved to a new domain is searched at the new one at once: the saved links move
 * and the search box is rebuilt, without waiting for the night (Gueta, from a staging address).
 */
final class StoreMovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_the_connection_moves_the_links_and_rebuilds_the_search(): void
    {
        $shop = Shop::factory()->create(['domain' => 'shop.guetaavigdor.co.il']);
        $tenant = app(TenantContext::class);
        $connection = $tenant->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://guetaavigdor.ussl.co', 'access_token' => 'lat_'.str_repeat('a', 48),
        ]));
        $product = $tenant->run($shop->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'hash' => 'h1', 'payload' => [],
            'title' => 'שולחן נגרים מקצועי',
            'url' => 'https://guetaavigdor.ussl.co/product/%d7%a9%d7%95%d7%9c%d7%97%d7%9f/',
            'image_url' => 'https://guetaavigdor.ussl.co/wp-content/uploads/table.jpg',
        ]));
        $cdn = $tenant->run($shop->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => '2', 'type' => 'simple', 'status' => 'publish', 'hash' => 'h2', 'payload' => [],
            'title' => 'מברגה', 'url' => 'https://guetaavigdor.ussl.co/product/b/',
            'image_url' => 'https://cdn.example.com/b.jpg',
        ]));

        FollowShopDomain::moveToShopDomain($tenant->runUnscoped(fn () => StoreConnection::query()->findOrFail($connection->id))->load('shop'));

        $tenant->run($shop->id, function () use ($product, $cdn): void {
            $product = $product->fresh();
            $this->assertSame('https://shop.guetaavigdor.co.il/product/%d7%a9%d7%95%d7%9c%d7%97%d7%9f/', $product->url);
            $this->assertSame('https://shop.guetaavigdor.co.il/wp-content/uploads/table.jpg', $product->image_url);
            $this->assertSame('https://cdn.example.com/b.jpg', $cdn->fresh()->image_url, 'a picture on another host stays');

            $index = json_encode(SearchIndex::query()->firstOrFail()->toArray(), JSON_UNESCAPED_SLASHES);
            $this->assertStringContainsString('shop.guetaavigdor.co.il/product/', $index, 'the search box was rebuilt now');
            $this->assertStringNotContainsString('guetaavigdor.ussl.co', $index);
        });
    }

    public function test_an_address_changed_by_hand_moves_the_links_too(): void
    {
        $shop = Shop::factory()->create(['domain' => 'shop.guetaavigdor.co.il']);
        $tenant = app(TenantContext::class);
        $connection = $tenant->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://guetaavigdor.ussl.co', 'access_token' => 'lat_'.str_repeat('b', 48),
        ]));
        $product = $tenant->run($shop->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'hash' => 'h1', 'payload' => [],
            'title' => 'מברגה', 'url' => 'https://guetaavigdor.ussl.co/product/a/', 'image_url' => 'https://guetaavigdor.ussl.co/a.jpg',
        ]));

        // The edit form saves the connection with a new address.
        $tenant->runUnscoped(fn () => StoreConnection::query()->findOrFail($connection->id)->update(['site_url' => 'https://shop.guetaavigdor.co.il/']));

        $this->assertSame('https://shop.guetaavigdor.co.il/a.jpg', $tenant->run($shop->id, fn () => $product->fresh()->image_url));
    }

    public function test_a_move_to_the_stores_www_moves_every_link_and_reads_no_picture_again(): void
    {
        // Sela Meir, 28.10: its connection at sellameir.com, which sends every link on to www.sellameir.com, a second
        // picture of each product and its kept answers still at the staging copy.
        $shop = Shop::factory()->create(['domain' => 'sellameir.com']);
        $tenant = app(TenantContext::class);
        $connection = $tenant->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id, 'site_url' => 'https://sellameir.com', 'access_token' => 'lat_'.str_repeat('c', 48),
        ]));
        $caption = trim((string) Settings::get('retrieval.caption_model'));
        [$product, $picture, $answer] = $tenant->run($shop->id, function () use ($shop, $caption): array {
            $product = CatalogProduct::query()->create([
                'shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'hash' => 'h1', 'title' => 'ספר',
                'url' => 'https://sellameir.com/product/a/', 'image_url' => 'https://sellameir.com/wp-content/uploads/a.jpg',
                'payload' => ['images' => [['url' => 'https://sellameir.com/wp-content/uploads/a.jpg'], ['url' => 'https://sellameir.ussl.co/wp-content/uploads/a2.jpg']],
                    'description' => '<p>ראו https://sellameir.com/about/</p>'],
            ]);
            CatalogVariant::query()->create(['shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => 'v1', 'image_url' => 'https://sellameir.com/wp-content/uploads/v.jpg']);
            $hash = hash('sha256', (string) $product->image_url);
            $picture = RetrievalImage::query()->create([
                'shop_id' => $shop->id, 'product_id' => $product->id, 'external_id' => '1', 'title' => 'ספר', 'image_url' => $product->image_url, 'url_hash' => $hash,
                'embedding_model' => 'm', 'embedded_at' => now(), 'caption_key' => CaptionImages::key($hash, $caption, 'Hebrew'), 'caption_model' => 't',
            ]);
            $answer = AssistantAnswer::query()->create([
                'shop_id' => $shop->id, 'question' => 'איך מגיעים?', 'question_key' => 'how', 'answer' => 'הכול בדף https://sellameir.ussl.co/checkout/ ובחנות.',
                'sources' => [['title' => 'תשלום', 'url' => 'https://sellameir.ussl.co/checkout/'], ['title' => 'אחר', 'url' => 'https://cdn.example.com/x']],
                'outcome' => AssistantAnswer::ANSWERED, 'status' => AssistantAnswer::SHOWN, 'prompt_version' => 1, 'asked_count' => 1, 'last_asked_at' => now(),
            ]);

            return [$product, $picture, $answer];
        });

        $tenant->runUnscoped(fn () => StoreConnection::query()->findOrFail($connection->id)->update(['site_url' => 'https://www.sellameir.com']));
        event(new StoreAddressMoved($shop->id, 'sellameir.ussl.co', 'www.sellameir.com'));

        $tenant->run($shop->id, function () use ($product, $picture, $answer, $caption): void {
            $product = $product->fresh();
            $this->assertSame('https://www.sellameir.com/product/a/', $product->url, 'a move to its www moves the links');
            $this->assertSame('https://www.sellameir.com/wp-content/uploads/a.jpg', $product->image_url);
            $this->assertSame(['https://www.sellameir.com/wp-content/uploads/a.jpg', 'https://www.sellameir.com/wp-content/uploads/a2.jpg'], array_column($product->payload['images'], 'url'));
            $this->assertSame('<p>ראו https://sellameir.com/about/</p>', $product->payload['description'], 'the store\'s words stay');
            $this->assertSame('https://www.sellameir.com/wp-content/uploads/v.jpg', CatalogVariant::query()->sole()->image_url);

            $picture = $picture->fresh();
            $this->assertSame($product->image_url, $picture->image_url);
            $this->assertSame(hash('sha256', $product->image_url), $picture->url_hash);
            $this->assertNotNull($picture->embedded_at, 'its vector kept');
            $this->assertSame(CaptionImages::key($picture->url_hash, $caption, 'Hebrew'), $picture->caption_key, 'its caption kept');

            $answer = $answer->fresh();
            $this->assertSame('הכול בדף https://www.sellameir.com/checkout/ ובחנות.', $answer->answer);
            $this->assertSame(['https://www.sellameir.com/checkout/', 'https://cdn.example.com/x'], array_column($answer->sources, 'url'));

            $index = json_encode(SearchIndex::query()->firstOrFail()->toArray(), JSON_UNESCAPED_SLASHES);
            $this->assertStringNotContainsString('ussl.co', $index);
            $this->assertStringNotContainsString('://sellameir.com', $index);
        });
    }

    public function test_sela_meir_is_read_at_www(): void
    {
        $shop = Shop::factory()->create(['domain' => 'sellameir.com']);
        $other = Shop::factory()->create(['domain' => 'other.co.il']);
        $tenant = app(TenantContext::class);
        $tenant->runUnscoped(function () use ($shop, $other): void {
            StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://sellameir.com', 'access_token' => 'lat_'.str_repeat('d', 48)]);
            StoreConnection::query()->create(['shop_id' => $other->id, 'site_url' => 'https://other.co.il', 'access_token' => 'lat_'.str_repeat('e', 48)]);
        });
        $product = $tenant->run($shop->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $shop->id, 'external_id' => '1', 'type' => 'simple', 'status' => 'publish', 'hash' => 'h1', 'title' => 'ספר',
            'url' => 'https://sellameir.com/product/a/', 'image_url' => 'https://sellameir.ussl.co/a.jpg', 'payload' => [],
        ]));

        (require base_path('app/Modules/Connections/Database/Migrations/2026_10_28_700001_sela_meir_is_read_at_www.php'))->up();

        $this->assertSame(['https://www.sellameir.com', 'https://other.co.il'], $tenant->runUnscoped(fn () => StoreConnection::query()->orderBy('created_at')->orderBy('id')->pluck('site_url')->all()));
        $product = $tenant->run($shop->id, fn () => $product->fresh());
        $this->assertSame('https://www.sellameir.com/product/a/', $product->url);
        $this->assertSame('https://www.sellameir.com/a.jpg', $product->image_url);
        $this->assertSame('sellameir.com', $shop->fresh()->domain);
    }
}
