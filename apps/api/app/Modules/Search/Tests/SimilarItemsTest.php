<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Contracts\RelatedByTags;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Similar items" on a result: by picture where the pictures are scanned, by meaning otherwise,
 * from stored vectors only; never the product itself, what is in stock first. The drawer and its
 * side are the shop's choice, read by the box with the rest of its settings.
 */
final class SimilarItemsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    public bool $pictures = true;

    /** @var array<string, int> which kind of nearness was asked, how many times */
    public array $asked = ['picture' => 0, 'text' => 0];

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['301' => ['Veja Panenka', true], '302' => ['Veja Volley', false], '303' => ['Veja Campo', true]] as $id => [$title, $stock]) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish',
                    'title' => $title, 'url' => "https://www.store.test/p/{$id}", 'image_url' => null,
                    'price' => '419.94', 'currency' => 'ILS', 'in_stock' => $stock, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [],
                ]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $test = $this;
        $this->app->instance(SemanticSearch::class, new class($test) implements SemanticSearch
        {
            public function __construct(private readonly SimilarItemsTest $test) {}

            public function similarTo(string $source, string $sourceId, array $sources, int $limit): array
            {
                $this->test->asked['text']++;

                return [['source' => 'product', 'source_id' => 'x', 'external_id' => '303', 'title' => 'Veja Campo', 'similarity' => 0.8]];
            }

            public function nearText(string $shopId, string $text, array $sources, int $limit): array
            {
                return [];
            }

            public function lookAlike(string $productId, int $limit): array
            {
                $this->test->asked['picture']++;

                return [
                    ['product_id' => 'a', 'external_id' => '301', 'title' => 'Veja Panenka', 'similarity' => 1.0],
                    ['product_id' => 'b', 'external_id' => '302', 'title' => 'Veja Volley', 'similarity' => 0.9],
                    ['product_id' => 'c', 'external_id' => '303', 'title' => 'Veja Campo', 'similarity' => 0.8],
                ];
            }

            public function picturesNearText(string $shopId, string $text, int $limit): array
            {
                return [];
            }

            public function picturesNearPhoto(string $shopId, string $mime, string $bytes, int $limit): array
            {
                return [];
            }

            public function picturesReady(): bool
            {
                return $this->test->pictures;
            }

            public function ready(): bool
            {
                return true;
            }
        });
    }

    public function test_similar_items_come_by_picture_and_meaning_in_stock_first_and_never_the_product_itself(): void
    {
        $products = $this->similar('301')->assertOk()->json('products');

        $this->assertSame(['303', '302'], array_column($products, 'external_id'), 'not the product itself, and what is in stock first');
        $this->assertSame(['picture' => 1, 'text' => 1], $this->asked, 'the picture and the meaning together, now');
    }

    public function test_similar_items_are_of_its_own_kind_or_none(): void
    {
        $type = fn (string $id, string $kind) => app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->where('external_id', $id)->update(['payload' => ['product_type' => $kind]]));
        $type('301', 'Sneakers');
        $type('302', 'Socks');
        $type('303', 'sneakers ');
        LoadedIndex::forget();

        $this->assertSame(['303'], array_column($this->similar('301')->json('products'), 'external_id'), 'socks look alike on a white background, and are not similar');

        $type('303', 'Laces');
        LoadedIndex::forget();
        $this->assertSame([], $this->similar('301')->json('products'), 'nothing of its kind: none, never related ones');
    }

    public function test_without_types_the_tags_only_some_products_carry_say_its_kind(): void
    {
        // A Shopify store with tags only: every product is in "כל המוצרים", which says nothing.
        $tags = fn (string $id, array $tags) => app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->where('external_id', $id)->update(['payload' => ['tags' => $tags]]));
        $tags('301', ['כל המוצרים', 'חיתולי במבוק']);
        $tags('302', ['כל המוצרים', 'טיפוח לתינוק']);
        $tags('303', ['כל המוצרים', 'חיתולי במבוק']);
        LoadedIndex::forget();

        $this->assertSame(['303'], array_column($this->similar('301')->json('products'), 'external_id'));
        $this->assertSame([], $this->similar('302')->json('products'), 'a baby cream has no swaddles for similar items');
    }

    public function test_the_tags_a_product_shares_come_before_what_its_description_reads_near(): void
    {
        // Sela: "חטוף" shares "חטופים ושבי" with "מפגש עם עצמי בגיהינום"; its description reads near "דרקולה" (303 here)
        Settings::set('search.similar_by', 'meaning', $this->shop->id);
        $this->app->instance(RelatedByTags::class, new class implements RelatedByTags
        {
            public function to(string $shopId, string $externalId, int $limit): array
            {
                return [['external_id' => '302', 'score' => 0.8]];
            }
        });

        $this->assertSame(['302', '303'], array_column($this->similar('301', 2)->json('products'), 'external_id'), 'the one sharing its tags first, the meaning after, to fill');
        $this->assertSame(['302'], array_column($this->similar('301', 1)->json('products'), 'external_id'), 'enough share its tags: only they');
    }

    public function test_the_shop_chooses_similar_items_by_content_or_by_picture(): void
    {
        // A bookshop whose bundles look alike: by content alone, the pictures never asked.
        Settings::set('search.similar_by', 'meaning', $this->shop->id);
        $this->similar('301')->assertOk();
        $this->assertSame(['picture' => 0, 'text' => 1], $this->asked);

        Settings::set('search.similar_by', 'picture', $this->shop->id);
        $this->similar('302')->assertOk();
        $this->assertSame(['picture' => 1, 'text' => 1], $this->asked, 'by picture alone: the meaning not asked');
    }

    public function test_a_cover_colour_does_not_reorder_them_where_the_shop_filters_by_no_colour(): void
    {
        // Sela: "חטוף" has a black cover and the nearest book a green one; the jewellery rule (yellow gold first)
        // pushed it past the twelve shown. Without a colour among the shop's filters the order is the nearness.
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['301' => 'כריכה שחורה', '302' => 'כריכה ירוקה', '303' => 'כריכה שחורה'] as $id => $cover) {
                CatalogProduct::query()->where('external_id', $id)->update(['in_stock' => true, 'title' => 'ספר '.$id.' '.$cover]);
            }
        });
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        Settings::set('search.similar_by', 'picture', $this->shop->id);

        $this->assertSame(['302', '303'], array_column($this->similar('301')->json('products'), 'external_id'));
    }

    public function test_without_scanned_pictures_they_come_by_meaning(): void
    {
        $this->pictures = false;

        $this->assertSame(['303'], array_column($this->similar('301')->json('products'), 'external_id'));
        $this->assertSame(['picture' => 0, 'text' => 1], $this->asked);
    }

    public function test_the_shop_chooses_whether_similar_items_show(): void
    {
        $config = $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index')->assertOk()->json('config');
        $this->assertTrue($config['similar']);
        // When the team answers WhatsApp, read in the browser; and whether a name and a way back can be left instead.
        $this->assertCount(7, $config['hours']['days']);
        $this->assertSame('Asia/Jerusalem', $config['hours']['timezone']);
        // by the shop's own clock (shops.timezone), as the product page's hours (AnsweringZone)
        $this->shop->forceFill(['timezone' => 'America/New_York'])->save();
        Cache::flush();
        $this->assertSame('America/New_York', $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/index')->json('config.hours.timezone'));
        $this->assertTrue($config['callbacks']);

        Features::override('search.similar', false, $this->shop->id);
        $this->similar('301')->assertNotFound();
        $this->similar('bad id!')->assertNotFound();
    }

    public function test_a_size_that_is_gone_is_helped_only_where_the_shop_turned_it_on(): void
    {
        $sizes = fn () => $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/sizes?locale=he')->assertOk();

        $this->assertSame(['enabled' => false, 'labels' => []], $sizes()->json(), 'off until the shop turns it on: the store page stays as it is');

        Features::override('search.size_alternatives', true, $this->shop->id);
        $on = $sizes()->json();
        $this->assertTrue($on['enabled']);
        $this->assertSame('המידה לא במלאי? לחצו על המידה להצגת פריטים דומים במידה המבוקשת', $on['labels']['size_gone_banner']);
        $this->assertSame('פריטים דומים במידה :size', $on['labels']['size_similar_title'], 'the size is filled in the browser');

        // The page keeps only the similar items that have the size, so it may ask for more of them, within a bound.
        $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/similar?id=301&limit=500')->assertOk();
        $this->assertSame(['303', '302'], array_column($this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/similar?id=301&limit=24')->json('products'), 'external_id'));

        Features::override('search.similar', false, $this->shop->id);
        $this->assertFalse($sizes()->json('enabled'), 'no similar items, no help');
    }

    private function similar(string $id, ?int $limit = null): TestResponse
    {
        return $this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/similar?id='.rawurlencode($id).($limit === null ? '' : '&limit='.$limit));
    }
}
