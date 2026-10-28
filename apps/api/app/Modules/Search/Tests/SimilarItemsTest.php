<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Contracts\RelatedByTags;
use App\Modules\Search\Models\SearchIndex;
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

    public function test_asked_in_another_colour_none_of_its_own_colour_is_shown(): void
    {
        // Rocks: "יש טבעות דומות עם אבן בצבע אחר?" asked on an amethyst ring's page
        $title = fn (string $id, string $t) => app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->where('external_id', $id)->update(['title' => $t]));
        $title('301', 'טבעת עם אבן סגולה');
        $title('302', 'טבעת עם אבן סגולה כהה');
        $title('303', 'טבעת עם אבן כחולה');
        $this->travel(2)->seconds();
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $this->assertSame(['303'], array_column($this->get('/api/v1/search/'.SiteKeys::site(self::TOKEN).'/similar?id=301&colour=other')->json('products'), 'external_id'), 'the blue one, never the other purple one');
        $this->assertContains('302', array_column($this->similar('301')->json('products'), 'external_id'), 'asked plainly, the purple one is alike');
    }

    public function test_similar_items_come_by_picture_and_meaning_in_stock_first_and_never_the_product_itself(): void
    {
        $products = $this->similar('301')->assertOk()->json('products');

        $this->assertSame(['303', '302'], array_column($products, 'external_id'), 'not the product itself, and what is in stock first');
        $this->assertSame(['picture' => 1, 'text' => 1], $this->asked, 'the picture and the meaning together, now');
    }

    public function test_similar_items_are_of_its_own_kind_or_none(): void
    {
        // a catalogue changes after the index was built, not in the same second: the kinds are counted again
        $this->travel(2)->seconds();
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
        // a catalogue changes after the index was built, not in the same second: the kinds are counted again
        $this->travel(2)->seconds();
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

    public function test_where_looks_matter_what_looks_alike_leads_and_its_own_colour_and_print_first(): void
    {
        // Vans: a grey sneaker's alike were every shoe of its collection, and a leopard backpack's a red bag first.
        // Where looks matter, the pictures lead, not the tags; and among them what shares the picture's words.
        Settings::set('search.similar_by', 'both', $this->shop->id);
        $this->app->instance(RelatedByTags::class, new class implements RelatedByTags
        {
            public function to(string $shopId, string $externalId, int $limit): array
            {
                return [['external_id' => '303', 'score' => 0.9]];
            }
        });
        app(TenantContext::class)->run($this->shop->id, function (): void {
            CatalogProduct::query()->where('external_id', '302')->update(['in_stock' => true]);
            foreach (['301' => ['נמר', 'חום', 'שחור'], '302' => ['אדום'], '303' => ['נמר', 'חום']] as $id => $words) {
                $product = CatalogProduct::query()->where('external_id', $id)->sole();
                RetrievalImage::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'external_id' => $id, 'title' => $product->title,
                    'image_url' => "https://www.store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', $id), 'caption_words' => $words]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();

        // the pictures put 302 before 303; the tags would have shown 303 alone. 303 is a brown leopard as it is; a plain
        // red bag is no leopard backpack's alike, however near its picture (Story: a striped and a floral shirt were a brown
        // overshirt's, for being button shirts)
        $this->assertSame(['303'], array_column($this->similar('301', 2)->json('products'), 'external_id'));

        app(TenantContext::class)->run($this->shop->id, fn () => RetrievalImage::query()->where('external_id', '303')->update(['caption_words' => json_encode(['נמר', 'כחול'])]));
        // a blue leopard is its alike in another colour: its colour orders, never leaves out (Rocks, 26.10: a wide ring with
        // white stones is like the same ring with green ones)
        $this->assertSame(['303'], array_column($this->similar('301', 2)->json('products'), 'external_id'), 'of its print in another colour');
        // and the shopper is offered, apart, what of its kind its look left out ("אולי אלה יעניינו אתכם"): the plain bag
        $this->assertSame(['302'], array_column($this->similar('301', 2)->json('others'), 'external_id'));

        // its picture names no colour: its print still keeps the plain bag apart
        app(TenantContext::class)->run($this->shop->id, fn () => RetrievalImage::query()->where('external_id', '301')->update(['caption_words' => json_encode(['נמר'])]));
        $this->assertSame(['303'], array_column($this->similar('301', 2)->json('products'), 'external_id'));
    }

    public function test_covered_in_a_print_its_alike_covered_in_it_come_before_those_with_it_on_a_logo(): void
    {
        // Vans: a checkered slide's alike should be the shoes covered in checks first, and the ones with checks on a logo
        // last, whatever order the pictures put them in.
        Settings::set('search.similar_by', 'both', $this->shop->id);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            CatalogProduct::query()->where('external_id', '302')->update(['in_stock' => true]);
            foreach (['301' => 'most', '302' => 'detail', '303' => 'all'] as $id => $covers) {
                $product = CatalogProduct::query()->where('external_id', $id)->sole();
                RetrievalImage::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'external_id' => $id, 'title' => $product->title,
                    'image_url' => "https://www.store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', $id), 'caption_words' => ['נעלי ספורט', 'משבצות', 'שחור'], 'caption_pattern' => ['name' => 'משבצות', 'covers' => $covers]]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();

        $this->assertSame(['303', '302'], array_column($this->similar('301', 2)->json('products'), 'external_id'), 'covered in checks before checks on a logo, though the pictures put 302 first');
    }

    public function test_what_is_drawn_on_it_is_part_of_what_sets_it_apart_for_its_alike(): void
    {
        // Vans: a T-shirt with a drawing of a shoe is like the other T-shirts with that drawing before a plain one the
        // pictures put first; the drawing is no word of it in a search for words, and still its own for its alike.
        Settings::set('search.similar_by', 'both', $this->shop->id);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            CatalogProduct::query()->where('external_id', '302')->update(['in_stock' => true]);
            foreach (['301' => ['חולצת טי', ['חולצת טי', 'לבן', 'איור נעל ספורט']], '302' => ['חולצת טי Basic', ['חולצת טי', 'לבן']], '303' => ['חולצת טי Trip', ['חולצת טי', 'לבן', 'איור נעל ספורט']]] as $id => [$title, $words]) {
                CatalogProduct::query()->where('external_id', $id)->update(['title' => $title]);
                $product = CatalogProduct::query()->where('external_id', $id)->sole();
                RetrievalImage::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'external_id' => $id, 'title' => $title,
                    'image_url' => "https://www.store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', $id), 'caption_words' => $words]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();

        $this->assertSame(['303', '302'], array_column($this->similar('301', 2)->json('products'), 'external_id'), 'the shirt with the same drawing first, though the pictures put the plain one before it');
    }

    public function test_what_sets_it_apart_said_by_another_puts_that_one_first_whatever_its_picture_order(): void
    {
        // Rocks, 26.10: a wide ring's alike were rings of other shapes the pictures put first; "רחבה" is what sets it
        // apart in a store of rings, "טבעת" and "משובצת" nothing
        Settings::set('search.similar_by', 'both', $this->shop->id);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['301' => 'טבעת רחבה משובצת', '302' => 'טבעת משובצת אמרלד', '303' => 'טבעת רחבה משובצת יהלומים'] as $id => $title) {
                CatalogProduct::query()->where('external_id', $id)->update(['title' => $title, 'in_stock' => true]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();

        $this->assertSame(['303', '302'], array_column($this->similar('301', 2)->json('products'), 'external_id'), 'the wide ring first, though its picture came after');
    }

    public function test_a_ring_of_its_stone_colour_is_its_colour_wherever_its_title_names_the_stone(): void
    {
        // Rocks, 28.10: "טבעת וינטג' בריטית משובצת ספיר" names its sapphire four words after "טבעת", and the store's tag
        // "ספיר, רובי, אמרלד" puts a ruby and an emerald beside "טבעות": read as a ring of another colour, it went after
        // every ring whose title says "טבעת סוליטר משובצת ספיר"
        Settings::set('search.similar_by', 'both', $this->shop->id);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach ([
                '301' => ["טבעת וינטג' משובצת אבן ספיר במרכז ויהלומים", ['טבעת', 'זהב לבן', 'אבן מרכזית אובלית', 'כחול כהה', 'הילה']],
                '302' => ["טבעת וינטג' בריטית משובצת ספיר כהה ויהלומים", ['טבעת', 'זהב לבן', 'אבן מרכזית', 'אובלית', 'כחול כהה', 'הילה']],
                '303' => ['טבעת סוליטר משובצת ספיר', ['טבעת', 'כחול כהה', 'סוליטר']],
            ] as $id => [$title, $words]) {
                CatalogProduct::query()->where('external_id', $id)->update(['title' => $title, 'in_stock' => true, 'payload' => ['tags' => ['טבעות ספיר', 'ספיר, רובי, אמרלד']]]);
                $product = CatalogProduct::query()->where('external_id', $id)->sole();
                RetrievalImage::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'external_id' => $id, 'title' => $title,
                    'image_url' => "https://www.store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', $id), 'caption_words' => $words]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        // the shop filters by stone colour, as Rocks does: its colours order its alike; and the words Rocks' index holds
        // for those two rings (its tags, its picture's words), as they were
        app(TenantContext::class)->run($this->shop->id, function (): void {
            $index = SearchIndex::query()->sole();
            $items = json_decode((string) $index->items, true);
            $items['facets'][] = ['k' => 'stone_color', 'l' => 'צבע אבן', 'v' => [['k' => 'blue', 'l' => 'כחול']]];
            $kw = [
                'p:302' => "sivan 70729 טבעות טבעות ספיר ספיר, רובי, אמרלד Not Sale טבעת זהב שני צבעים ספיר זהב 18K כחול לבן שקוף וינטג' זהב צהוב זהוב אבן מרכזית כחול כהה אובלית הילה של אבנים קטנות שקופה נוצצת כסוף פס דק מבריק עדין",
                'p:303' => 'פוזיילוב 61596 טבעות טבעות זהב לבן טבעות ספיר ספיר, רובי, אמרלד Not Sale טבעת זהב לבן ספיר 14K כחול לבן שקוף טבעת סוליטר כסוף אבן מרכזית אובלית כחול כהה שקופה נוצצת ארבע שיניים',
            ];
            $items['items'] = array_map(fn (array $item): array => isset($kw[$item['id']]) ? ['kw' => $kw[$item['id']]] + $item : $item, $items['items']);
            $index->update(['items' => json_encode($items, JSON_UNESCAPED_UNICODE)]);
        });
        LoadedIndex::forget();

        $this->assertSame(['302', '303'], array_column($this->similar('301', 2)->json('products'), 'external_id'), 'both blue: the pictures and its words order them');
    }

    public function test_a_word_of_shape_is_no_print_where_the_pictures_reader_saw_none(): void
    {
        // Rocks, 28.10: a Diana ring "פרחוני" (flower-shaped) was taken for a print and left out of a plain ring's alike
        Settings::set('search.similar_by', 'both', $this->shop->id);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach ([
                '301' => [['טבעת', 'אבן כחולה', 'הילה'], ['covers' => 'none']],
                '302' => [['טבעת', 'אבן כחולה', 'פרחוני'], ['covers' => 'none']],
                '303' => [['טבעת', 'אבן כחולה', 'פרחוני'], null],
            ] as $id => [$words, $pattern]) {
                CatalogProduct::query()->where('external_id', $id)->update(['title' => 'טבעת '.$id, 'in_stock' => true]);
                $product = CatalogProduct::query()->where('external_id', $id)->sole();
                RetrievalImage::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'external_id' => $id, 'title' => 'טבעת '.$id,
                    'image_url' => "https://www.store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', $id), 'caption_words' => $words, 'caption_pattern' => $pattern]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();

        $this->assertSame(['302'], array_column($this->similar('301', 2)->json('products'), 'external_id'), 'its reader saw no print on 302; 303, read before it said, still goes by its words');
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
