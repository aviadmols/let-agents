<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Contracts\ProductsNamed;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The search box from end to end: the nightly index, the suggestions' download, a search by
 * spelling and by meaning, the counts, the synonyms, and the store's own results page.
 */
final class SearchFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private Shop $shop;

    private string $site;

    /** @var list<array{text: string, sources: list<string>}> */
    private array $meaningAsked = [];

    /** @var list<array<string, mixed>> what the fake index by meaning answers */
    private array $meaning = [];

    /** @var list<array<string, mixed>> what the fake picture index answers */
    private array $pictures = [];

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);

        $test = $this;
        $this->app->instance(SemanticSearch::class, new class($test) implements SemanticSearch
        {
            public function __construct(private readonly SearchFlowTest $test) {}

            public function similarTo(string $source, string $sourceId, array $sources, int $limit): array
            {
                return [];
            }

            public function nearText(string $shopId, string $text, array $sources, int $limit): array
            {
                return $this->test->askedByMeaning($text, $sources);
            }

            public function lookAlike(string $productId, int $limit): array
            {
                return [];
            }

            public function picturesNearText(string $shopId, string $text, int $limit): array
            {
                return $this->test->askedByPicture($text);
            }

            public function picturesNearPhoto(string $shopId, string $mime, string $bytes, int $limit): array
            {
                return [];
            }

            public function picturesReady(): bool
            {
                return true;
            }

            public function ready(): bool
            {
                return true;
            }
        });

        $this->inShop(function (): void {
            $tools = CatalogCategory::query()->create($this->category('7', 'כלי עבודה חשמליים', 12));
            CatalogCategory::query()->create($this->category('8', 'קטגוריה ריקה', 0));
            $drill = $this->product('101', 'מברגה נטענת מקיטה 18V', ['brand' => 'Makita', 'sku' => 'DDF485']);
            $this->product('102', 'בורג איסכורית 6X40');
            $this->product('103', 'עץ אורן מוקצע 42X92', ['in_stock' => false]);
            $this->product('104', 'עץ אורן מוקצע 42X142');
            $this->product('105', 'מוצר שהוסר', ['removed_at' => now()]);
            $drill->categories()->attach($tools->id);
            CatalogContent::query()->create([
                'shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => '500', 'title' => 'איך מחברים קורות לדק',
                'url' => 'https://www.store.test/guide/deck', 'terms' => ['category' => ['מדריכים']], 'hash' => 'h500',
            ]);
        });
    }

    /** Called by the fake index by meaning. */
    public function askedByMeaning(string $text, array $sources): array
    {
        $this->meaningAsked[] = ['text' => $text, 'sources' => $sources];

        return $this->meaning;
    }

    /** Called by the fake picture index. */
    public function askedByPicture(string $text): array
    {
        return $this->pictures;
    }

    public function test_a_picture_can_find_what_no_name_says(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->pictures = [
            ['product_id' => 'x', 'external_id' => '104', 'title' => 'עץ אורן מוקצע 42X142', 'similarity' => 0.41],
            ['product_id' => 'y', 'external_id' => '102', 'title' => 'בורג איסכורית 6X40', 'similarity' => 0.2],
        ];

        $result = $this->search('קורה בהירה')->json();

        $this->assertTrue($result['semantic']);
        $this->assertSame(['104'], array_column($result['groups']['product'], 'external_id'), 'a picture below the floor stays out');

        Features::override('search.pictures', false, $this->shop->id);
        $this->assertSame(0, $this->search('קורה בהירה')->json('total'));
    }

    public function test_the_index_holds_what_the_store_publishes_and_nothing_else(): void
    {
        $run = app(BuildSearchIndex::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertSame(['product' => 4, 'content' => 1, 'category' => 1, 'synonyms' => 0, 'answer' => 0], $run->output['counts'], 'no removed product, no empty category');

        $items = collect(json_decode((string) $this->inShop(fn () => SearchIndex::query()->value('items')), true)['items'])->keyBy('id');
        $this->assertStringContainsString('Makita', $items['p:101']['kw']);
        $this->assertStringContainsString('DDF485', $items['p:101']['kw']);
        $this->assertStringContainsString('כלי עבודה חשמליים', $items['p:101']['kw']);
        $this->assertSame(0, $items['p:103']['s'], 'out of stock last night');
        $this->assertSame('https://www.store.test/guide/deck', $items['c:500']['url']);
        $this->assertArrayNotHasKey('price', $items['p:101'], 'prices come live from the store, never from here');
    }

    public function test_the_browser_downloads_the_index_once_and_only_from_the_store(): void
    {
        $this->getJson("/api/v1/search/{$this->site}/index")->assertOk()->assertJson(['enabled' => false, 'reason' => 'not_built']);

        app(BuildSearchIndex::class)->handle($this->shop->id);
        $response = $this->getJson("/api/v1/search/{$this->site}/index?locale=he", ['Origin' => 'https://www.store.test'])->assertOk();

        $response->assertJsonPath('enabled', true)
            ->assertJsonPath('config.selector', 'input[name="s"], input[type="search"]')
            ->assertJsonMissingPath('labels');
        $this->assertCount(6, $response->json('items'));

        $this->get("/api/v1/search/{$this->site}/index?locale=he", ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
        $this->getJson("/api/v1/search/{$this->site}/index", ['Origin' => 'https://evil.test'])->assertForbidden();
        $this->getJson('/api/v1/search/000000000000000000000000/index')->assertNotFound();

        Features::override('search.storefront', false, $this->shop->id);
        $this->getJson("/api/v1/search/{$this->site}/index")->assertOk()->assertJson(['enabled' => false]);
        $this->getJson("/api/v1/search/{$this->site}?q=בורג")->assertNotFound();
    }

    public function test_a_typo_finds_the_product_and_the_search_is_counted(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $result = $this->search('מקיטא')->assertOk()->json();

        $this->assertSame('מקיטא', $result['query']);
        $this->assertSame('מברגה נטענת מקיטה 18V', $result['groups']['product'][0]['title']);
        $this->assertSame('101', $result['groups']['product'][0]['external_id']);
        $this->assertTrue($result['groups']['product'][0]['buy']);

        $this->search('מקיטא', counted: true)->assertOk();
        $this->search('גגגגגג')->assertOk()->assertJsonPath('total', 0);

        $terms = $this->inShop(fn () => SearchTerm::query()->get()->keyBy('query'));
        $this->assertSame(1, $terms['מקיטא']->searches, 'a search the tab already counted is not counted again');
        $this->assertSame(0, $terms['מקיטא']->empty);
        $this->assertSame(1, $terms['גגגגגג']->empty, 'a search that found nothing is marked so');
    }

    public function test_what_was_in_stock_comes_first_and_categories_and_guides_have_their_own_groups(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $result = $this->search('עץ אורן')->json();
        $this->assertSame(['104', '103'], array_column($result['groups']['product'], 'external_id'), 'the one out of stock last night goes last');

        $result = $this->search('כלי עבודה')->json();
        $this->assertSame('כלי עבודה חשמליים', $result['groups']['category'][0]['title']);
        $this->assertSame(12, $result['groups']['category'][0]['products']);
    }

    public function test_meaning_adds_what_spelling_cannot_find_and_never_runs_for_short_queries(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->meaning = [
            ['source' => 'content', 'source_id' => 'x', 'external_id' => '500', 'title' => 'איך מחברים קורות לדק', 'similarity' => 0.52],
            ['source' => 'product', 'source_id' => 'y', 'external_id' => '102', 'title' => 'בורג איסכורית 6X40', 'similarity' => 0.41],
            ['source' => 'product', 'source_id' => 'z', 'external_id' => '104', 'title' => 'עץ אורן מוקצע 42X142', 'similarity' => 0.12],
            ['source' => 'product', 'source_id' => 'w', 'external_id' => '999', 'title' => 'not in the index', 'similarity' => 0.9],
        ];

        // A guide by meaning alone shows only where the shop wants it.
        $this->assertSame([], $this->search('משהו לחבר קרשים')->json('groups.content'), 'off by default: a guide must hold the words');
        $this->meaningAsked = [];
        Features::override('search.content_by_meaning', true, $this->shop->id);

        $result = $this->search('משהו לחבר קרשים')->json();

        $this->assertTrue($result['semantic']);
        $this->assertSame(['102'], array_column($result['groups']['product'], 'external_id'), 'below the similarity floor, or not published, it stays out');
        $this->assertSame(['500'], array_column($result['groups']['content'], 'external_id'));
        $this->assertSame([['text' => 'משהו לחבר קרשים', 'sources' => ['product', 'content']]], $this->meaningAsked, 'asked once, with the words as written, finals at their ends');

        $this->meaningAsked = [];
        $this->search('בו')->assertOk();
        $this->assertSame([], $this->meaningAsked, 'two letters are searched by spelling only');

        Features::override('search.semantic', false, $this->shop->id);
        $this->search('משהו לחבר קרשים')->assertOk()->assertJsonPath('semantic', false);
        $this->assertSame([], $this->meaningAsked);
    }

    public function test_when_the_words_found_a_few_the_meaning_adds_only_what_is_very_near(): void
    {
        // The meaning pass follows every search now: it must not pad what the words already found.
        foreach (['801' => 'מדף אורן 60', '802' => 'מדף אורן 80', '803' => 'מדף אורן 100', '804' => 'מדף אורן 120'] as $id => $title) {
            $this->product($id, $title);
        }
        $this->product('805', 'ארון כתר פלסטיק');
        $this->product('806', 'כוורת מדפים מעץ');
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->meaning = [
            ['source' => 'product', 'source_id' => 'a', 'external_id' => '806', 'title' => 'כוורת מדפים מעץ', 'similarity' => 0.62],
            ['source' => 'product', 'source_id' => 'b', 'external_id' => '805', 'title' => 'ארון כתר פלסטיק', 'similarity' => 0.40],
        ];

        $ids = array_column($this->search('מדף')->json('groups.product'), 'external_id');

        $this->assertContains('801', $ids);
        $this->assertContains('806', $ids, 'very near by meaning: added');
        $this->assertNotContains('805', $ids, 'only somewhat near, when the words found four: left out');
    }

    public function test_a_sentence_no_product_holds_whole_shows_what_says_most_of_it_first(): void
    {
        // Story: "חולצה לבנה עם כיס קטן בצד לגברים" is held whole by no product, so meaning and pictures found it, and put
        // white T-shirts before the white shirt whose picture words say "כיס חזה".
        $this->inShop(function (): void {
            $this->product('901', 'טי שירט לבנה לגברים', ['payload' => ['tags' => ['חולצה']]]);
            $this->product('902', 'חולצה מכופתרת לגברים Mateo', ['payload' => ['tags' => ['לבן', 'כיס חזה']]]);
            $this->product('911', 'Men white tee', ['payload' => ['tags' => ['shirt']]]);
            $this->product('912', 'Men shirt Mateo', ['payload' => ['tags' => ['white pockets']]]);
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $near = fn (string $first, string $second): array => [
            ['source' => 'product', 'source_id' => 'a', 'external_id' => $first, 'title' => '', 'similarity' => 0.58],
            ['source' => 'product', 'source_id' => 'b', 'external_id' => $second, 'title' => '', 'similarity' => 0.55],
        ];

        $this->meaning = $near('901', '902');
        $this->assertSame('902', $this->search('חולצה לבנה עם כיס קטן בצד לגברים')->json('groups.product.0.external_id'));

        $this->meaning = $near('911', '912');
        $this->assertSame('912', $this->search('white shirt with a small side pocket for men')->json('groups.product.0.external_id'), 'the same in English, "pocket" saying "pockets"');
    }

    public function test_a_colour_asked_for_shows_what_its_picture_shows_most_before_what_has_it_as_a_detail(): void
    {
        // Vans: "חולצה לבנה טי שירט" showed a black T-shirt sold as "Black-White" (its print) among the white ones.
        $this->inShop(function (): void {
            foreach (['931' => ['חולצת טי Basic', ['חולצת טי', 'לבן', 'לוגו שחור']], '932' => ['חולצת טי Stretch Logo', ['חולצת טי', 'שחור', 'לבן']]] as $id => [$title, $words]) {
                $product = $this->product($id, $title, ['payload' => ['tags' => ['לבן', 'שחור']]]);
                RetrievalImage::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'external_id' => $id, 'title' => $title,
                    'image_url' => "https://www.store.test/i/{$id}.jpg", 'url_hash' => hash('sha256', $id), 'caption_words' => $words]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $this->assertSame(['931', '932'], array_column($this->search('חולצת טי לבנה')->json('groups.product'), 'external_id'), 'the white one, then the black one with white on it');
    }

    public function test_one_word_many_products_hold_as_typed_shows_only_them(): void
    {
        // Rocks: "טופז" found 93 topaz rings and, by meaning, citrine and yellow sapphire after them.
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->meaning = [['source' => 'product', 'source_id' => 'y', 'external_id' => '102', 'title' => 'בורג איסכורית 6X40', 'similarity' => 0.41]];

        Settings::set('search.word_enough', 3, $this->shop->id);
        $this->assertContains('102', array_column($this->search('אורן')->json('groups.product'), 'external_id'), 'two pine boards hold the word: few, the meaning fills in');

        Settings::set('search.word_enough', 2, $this->shop->id);
        $this->assertSame(['104', '103'], array_column($this->search('אורן')->json('groups.product'), 'external_id'), 'enough hold it as typed: only they');
    }

    public function test_a_word_the_catalogue_does_not_know_is_said_and_only_the_nearest_few_follow(): void
    {
        // Rocks: "חולצה עם האות M" showed 48 link chains by meaning (.40 to .45): the store sells no shirts.
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->meaning = [
            ['source' => 'product', 'source_id' => 'y', 'external_id' => '102', 'title' => 'בורג איסכורית 6X40', 'similarity' => 0.44],
            ['source' => 'product', 'source_id' => 'x', 'external_id' => '104', 'title' => 'עץ אורן מוקצע 42X142', 'similarity' => 0.62],
        ];

        Settings::set('search.unknown_results', 1, $this->shop->id);
        $shirt = $this->search('חולצה')->json();
        $this->assertSame(['חולצה'], $shirt['unknown'], 'the drawer says it is not here');
        $this->assertSame(0, $shirt['held'], 'no product holds the words: shown as nearest, not as found');
        $this->assertSame(['102'], array_column($shirt['groups']['product'], 'external_id'), 'only the nearest few');

        $this->assertSame([], $this->search('אורן')->json('unknown'), 'a word the catalogue has');
        $this->assertSame(2, $this->search('אורן')->json('held'));
    }

    public function test_guides_by_meaning_are_their_own_group_suggest_their_products_for_a_need_and_age(): void
    {
        $this->inShop(function (): void {
            CatalogContent::query()->where('external_id', '500')->update(['source_updated_at' => now()->subMonth()]);
            CatalogContent::query()->create([
                'shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => '501', 'title' => 'מדריך ישן לדקים',
                'url' => 'https://www.store.test/guide/old', 'terms' => [], 'hash' => 'h501', 'source_updated_at' => now()->subYears(3),
            ]);
            // A buying guide of three years ago has aged (models, prices); a how-to of five years has not.
            CatalogContent::query()->create([
                'shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => '502', 'title' => 'איך מחברים קרשים',
                'url' => 'https://www.store.test/guide/howto', 'terms' => [], 'hash' => 'h502', 'source_updated_at' => now()->subYears(5),
            ]);
            foreach (['501' => 'buying_guide', '502' => 'how_to'] as $external => $kind) {
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'content_id' => CatalogContent::query()->where('external_id', $external)->value('id'),
                    'kind' => FactKind::ContentKind, 'key' => 'content_kind', 'value_text' => $kind, 'origin' => FactOrigin::Model, 'status' => FactStatus::Approved, 'input_hash' => 'k'.$external, 'model' => 'm']);
            }
            $guide = CatalogContent::query()->where('external_id', '500')->sole();
            $pine = CatalogProduct::query()->where('external_id', '104')->sole();
            EnrichmentContentProduct::query()->create([
                'shop_id' => $this->shop->id, 'content_id' => $guide->id, 'product_id' => $pine->id,
                'rank' => 1, 'score' => 9, 'reasons' => ['mentioned' => true], 'computed_at' => now(),
            ]);
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        Features::override('search.content_by_meaning', true, $this->shop->id);
        $this->meaning = [
            ['source' => 'content', 'source_id' => 'h', 'external_id' => '502', 'title' => 'איך מחברים קרשים', 'similarity' => 0.56],
            ['source' => 'content', 'source_id' => 'o', 'external_id' => '501', 'title' => 'מדריך ישן לדקים', 'similarity' => 0.55],
            ['source' => 'content', 'source_id' => 'x', 'external_id' => '500', 'title' => 'איך מחברים קורות לדק', 'similarity' => 0.52],
            ['source' => 'product', 'source_id' => 'y', 'external_id' => '102', 'title' => 'בורג איסכורית 6X40', 'similarity' => 0.41],
        ];

        $result = $this->search('איך מחברים קרשים לדק?')->json();

        $this->assertSame(['502', '500', '501'], array_column($result['groups']['content'], 'external_id'), 'three years take the old buying guide below the newer one; a how-to does not age');
        $this->assertContains('104', array_column($result['groups']['product'], 'external_id'), 'what the nearest guides name is suggested');
        $this->assertSame('102', $result['groups']['product'][0]['external_id'], 'after what was found itself');

        // Not a question about a need: the guide is shown, its products are not suggested. No product holds the words and
        // the meaning is weak (.41): none is shown as if found; a store that wants weaker meaning lowers its threshold.
        $this->assertSame([], array_column($this->search('חיבור קרשים לדק')->json('groups.product'), 'external_id'));
        // ...unless the store itself answered a question holding the words (Mycolivia: "לישון" in its own "מה טוב לישון?")
        app(TenantContext::class)->run($this->shop->id, fn () => AssistantAnswer::query()->create(['shop_id' => $this->shop->id, 'question' => 'איך עושים חיבור קרשים לדק?', 'question_key' => 'kq',
            'answer' => 'עם ברגי דק.', 'outcome' => AssistantAnswer::ANSWERED, 'prompt_version' => 1, 'last_asked_at' => now(), 'shared_at' => now()]));
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->assertSame(['102'], array_column($this->search('חיבור קרשים לדק')->json('groups.product'), 'external_id'), 'the store answered about it: its nearest, a few');
        app(TenantContext::class)->run($this->shop->id, fn () => AssistantAnswer::query()->where('question_key', 'kq')->delete());
        LoadedIndex::forget();
        app(BuildSearchIndex::class)->handle($this->shop->id);
        Settings::set('search.meaning_only_min_similarity', 0.4, $this->shop->id);
        $this->assertSame(['102'], array_column($this->search('חיבור קרשים לדק')->json('groups.product'), 'external_id'));

        // How near a product found by meaning must be is the shop's too (it moves with the shop's words' model).
        Settings::set('search.semantic_min_similarity', 0.45, $this->shop->id);
        $this->assertSame([], array_column($this->search('חיבור קרשים לדק')->json('groups.product'), 'external_id'));
        $this->assertSame(0.3, Settings::get('search.semantic_min_similarity'), 'every other shop keeps the default');
    }

    public function test_with_a_price_only_what_holds_the_words_is_shown(): void
    {
        // "עגילים עד 500": a pendant is close to earrings by meaning, and the price leaves few earrings.
        $this->inShop(function (): void {
            $this->product('201', 'עגילים צמודים כדורי זהב', ['price' => '450.00']);
            $this->product('202', 'עגילי יהלום', ['price' => '900.00']);
            $this->product('203', 'תליון מפתח עדין', ['price' => '350.00']);
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->meaning = [
            ['source' => 'product', 'source_id' => 'a', 'external_id' => '203', 'title' => 'תליון מפתח עדין', 'similarity' => 0.6],
            ['source' => 'product', 'source_id' => 'b', 'external_id' => '201', 'title' => 'עגילים צמודים כדורי זהב', 'similarity' => 0.6],
        ];

        $this->assertSame(['201'], array_column($this->search('עגילים עד 500 ש"ח?')->json('groups.product'), 'external_id'));
        $this->assertContains('203', array_column($this->search('עגילים')->json('groups.product'), 'external_id'), 'without a price, meaning still adds');
    }

    public function test_the_assistant_reads_what_a_question_names_the_way_the_search_reads_its_words(): void
    {
        $this->inShop(function (): void {
            $earrings = CatalogCategory::query()->create($this->category('9', 'עגילים', 2));
            $this->product('201', 'עגילים צמודים כדורי זהב', ['price' => '450.00']);
            $this->product('203', 'תליון מפתח עדין', ['price' => '350.00']);
            $this->product('204', 'חישוקי זהב 14K', ['price' => '300.00'])->categories()->attach($earrings->id);
            $this->product('205', 'שרשרת חוליות זהב', ['price' => '700.00']);
            $this->product('206', 'צמיד טניס זהב', ['price' => '900.00']);
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $named = fn (string $question, array $ids): array => $this->inShop(fn () => app(ProductsNamed::class)->among($this->shop->id, $question, $ids));

        $this->assertSame(['201', '204'], $named('עגילים עד 500 ש"ח?', ['203', '201', '204']), 'the hoops by their category, not the pendant');
        $this->assertSame(['205'], $named('שרשראות עד 1000', ['203', '205']), 'a plural names the singular');
        // "צמיד" is close to "צמודים" by spelling; with bracelets in the store, studs are not bracelets.
        $this->assertSame([], $named('צמיד עד 500', ['201', '203']));
        $this->assertSame([], array_column($this->search('צמיד עד 500')->json('groups.product'), 'external_id'), 'no bracelet that cheap: nothing, not the studs');
        $this->assertSame(['206'], array_column($this->search('צמיד עד 1000')->json('groups.product'), 'external_id'));
    }

    public function test_while_typing_the_search_is_spelling_only_and_not_counted(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $result = $this->getJson("/api/v1/search/{$this->site}?q=".urlencode('מקיטא').'&typing=1', ['Origin' => 'https://www.store.test'])->assertOk()->json();

        $this->assertSame('101', $result['groups']['product'][0]['external_id'] ?? null, 'a typo still finds the drill');
        $this->assertFalse($result['semantic']);
        $this->assertSame([], $this->meaningAsked, 'no vector per keystroke');
        $this->assertSame(0, $this->inShop(fn () => SearchTerm::query()->count()), 'nothing counted while typing');

        // A pause in the typing: by meaning too, and still not counted
        $this->getJson("/api/v1/search/{$this->site}?q=".urlencode('מקיטא').'&typing=1&meaning=1', ['Origin' => 'https://www.store.test'])->assertOk();
        $this->assertNotSame([], $this->meaningAsked, 'a pause reads the words by meaning');
        $this->assertSame(0, $this->inShop(fn () => SearchTerm::query()->count()));
    }

    public function test_the_stores_own_search_page_is_searched_by_spelling_only_and_counted(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $result = $this->getJson("/api/v1/search/{$this->site}?q=".urlencode('מקיטא').'&spelling=1', ['Origin' => 'https://www.store.test'])->assertOk()->json();

        $this->assertSame('101', $result['groups']['product'][0]['external_id'] ?? null);
        $this->assertSame([], $this->meaningAsked, 'a page opening never reaches a model');
        $this->assertSame(1, $this->inShop(fn () => SearchTerm::query()->count()), 'but it is a search, and counted');
    }

    public function test_a_synonym_finds_the_products_the_store_names_differently(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->search('קרש')->assertJsonPath('total', 0);

        $this->inShop(fn () => SearchSynonym::query()->create(['shop_id' => $this->shop->id, 'term' => 'קרש', 'means' => 'עץ']));
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $result = $this->search('קרש')->json();
        $this->assertEqualsCanonicalizing(['103', '104'], array_column($result['groups']['product'], 'external_id'));
    }

    public function test_a_search_that_finds_hundreds_hands_the_drawer_every_one_to_filter_and_count(): void
    {
        // Rocks: "טבעת זהב לבן" found more than 600 rings, and the drawer counted and filtered 600.
        $this->inShop(function (): void {
            for ($i = 1; $i <= 700; $i++) {
                $this->product((string) (5000 + $i), "טבעת זהב לבן דגם {$i}");
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $ids = $this->getJson("/api/v1/search/{$this->site}?q=".urlencode('טבעת זהב לבן').'&typing=1', ['Origin' => 'https://www.store.test'])->assertOk()->json('ids');

        $this->assertCount(700, array_filter($ids, fn (string $id): bool => (int) substr($id, 2) > 5000));
    }

    public function test_a_shoppers_battery_is_the_stores_battery_while_typing(): void
    {
        // as at the hardware store: one drill shares "עם" with the words, the one with batteries says "סוללות"
        $this->inShop(function (): void {
            $this->product('301', 'מקדחה נטענת 18V כולל 2 סוללות ומטען');
            $this->product('302', 'מקדחה נטענת עם ידית צד');
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $result = $this->getJson("/api/v1/search/{$this->site}?q=".urlencode('מקדחה נטענת עם בטריה').'&typing=1', ['Origin' => 'https://www.store.test'])->assertOk()->json();

        $this->assertContains('301', array_column($result['groups']['product'], 'external_id'), '"בטריה" finds the store\'s "סוללות", by spelling alone');
    }

    public function test_the_storefront_counts_paused_searches_and_clicks_without_anything_about_the_shopper(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $this->call('POST', "/api/v1/search/{$this->site}/events", server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://www.store.test'], content: json_encode(['events' => [
            ['type' => 'search', 'q' => 'מקיטא ', 'results' => 1],
            ['type' => 'click', 'q' => 'מקיטא', 'id' => 'p:101', 'title' => 'מברגה נטענת מקיטה 18V'],
            ['type' => 'click', 'q' => 'מקיטא', 'id' => '<script>', 'title' => 'x'],
            ['type' => 'unknown', 'q' => 'x'],
        ]]))->assertNoContent();

        $term = $this->inShop(fn () => SearchTerm::query()->sole());
        $this->assertSame(['מקיטא', 1, 1], [$term->query, $term->searches, $term->clicks]);
        $click = $this->inShop(fn () => SearchClick::query()->sole());
        $this->assertSame(['p:101', 1], [$click->item, $click->clicks]);

        $this->call('POST', "/api/v1/search/{$this->site}/events", server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://evil.test'], content: '{"events":[]}')->assertForbidden();
    }

    public function test_the_stores_results_page_gets_the_same_order_signed(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);

        $result = $this->signed('/api/v1/plugin/'.$this->site.'/search?q='.urlencode('עץ אורן'))->assertOk()->json();

        $this->assertTrue($result['searched']);
        $this->assertSame(['104', '103'], $result['products']);
        $this->signed('/api/v1/plugin/'.$this->site.'/search?q=x', signature: str_repeat('0', 64))->assertUnauthorized();
    }

    public function test_search_never_calls_a_model_by_itself(): void
    {
        $calls = 0;
        $this->app->instance(Embedder::class, new class($calls) implements Embedder
        {
            public function __construct(private int &$calls) {}

            public function embed(AiProviderName $provider, string $model, array $texts, ?int $dimensions = null): Embeddings
            {
                $this->calls++;

                return new Embeddings([], 0);
            }
        });

        app(BuildSearchIndex::class)->handle($this->shop->id);
        $this->getJson("/api/v1/search/{$this->site}/index")->assertOk();
        $this->search('מקיטא')->assertOk();

        $this->assertSame(0, $calls, 'building, downloading and spelling search are code only; meaning goes through Retrieval, faked here');
    }

    public function test_the_shell_can_build_and_try(): void
    {
        $this->artisan('search', ['step' => 'index', 'target' => $this->shop->slug])->assertSuccessful();
        $this->artisan('search', ['step' => 'try', 'target' => $this->shop->slug, 'query' => 'איסקורית'])
            ->expectsOutputToContain('בורג איסכורית 6X40')
            ->assertSuccessful();

        Settings::set('search.keep_days', 30);
        $this->inShop(fn () => SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => now()->subDays(40)->toDateString(), 'query' => 'ישן', 'searches' => 3]));
        $this->artisan('search', ['step' => 'prune'])->assertSuccessful();
        $this->assertSame(0, $this->inShop(fn () => SearchTerm::query()->where('query', 'ישן')->count()));
    }

    private function search(string $query, bool $counted = false): TestResponse
    {
        return $this->getJson("/api/v1/search/{$this->site}?q=".urlencode($query).($counted ? '&counted=1' : ''), ['Origin' => 'https://www.store.test']);
    }

    private function signed(string $path, ?string $signature = null): TestResponse
    {
        $timestamp = (string) time();
        $signature ??= SiteKeys::signature(self::TOKEN, $timestamp, 'GET', $path, '');

        return $this->call('GET', $path, server: ['HTTP_X_LETAGENTS_TIMESTAMP' => $timestamp, 'HTTP_X_LETAGENTS_SIGNATURE' => $signature]);
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    /** @param array<string, mixed> $overrides */
    private function product(string $externalId, string $title, array $overrides = []): CatalogProduct
    {
        return CatalogProduct::query()->create(array_replace([
            'shop_id' => $this->shop->id, 'external_id' => $externalId, 'type' => 'simple', 'status' => 'publish',
            'title' => $title, 'url' => "https://www.store.test/p/{$externalId}", 'image_url' => "https://www.store.test/i/{$externalId}.jpg",
            'price' => '49.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$externalId}", 'payload' => [],
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function category(string $externalId, string $name, int $count): array
    {
        return [
            'shop_id' => $this->shop->id, 'external_id' => $externalId, 'name' => $name, 'path' => [$name],
            'product_count' => $count, 'url' => "https://www.store.test/c/{$externalId}", 'hash' => "c{$externalId}",
        ];
    }
}
