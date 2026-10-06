<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\Embedder;
use App\Modules\Ai\Contracts\Embeddings;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Retrieval\Contracts\SemanticSearch;
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
        $this->assertSame(['product' => 4, 'content' => 1, 'category' => 1, 'synonyms' => 0], $run->output['counts'], 'no removed product, no empty category');

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
            ->assertJsonPath('config.results', 'panel')
            ->assertJsonPath('labels.products', 'מוצרים');
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

        $result = $this->search('משהו לחבר קרשים')->json();

        $this->assertTrue($result['semantic']);
        $this->assertSame(['102'], array_column($result['groups']['product'], 'external_id'), 'below the similarity floor, or not published, it stays out');
        $this->assertSame(['500'], array_column($result['groups']['content'], 'external_id'));
        $this->assertSame([['text' => 'משהו לחבר קרשימ', 'sources' => ['product', 'content']]], $this->meaningAsked, 'asked once, with the normalized words');

        $this->meaningAsked = [];
        $this->search('בו')->assertOk();
        $this->assertSame([], $this->meaningAsked, 'two letters are searched by spelling only');

        Features::override('search.semantic', false, $this->shop->id);
        $this->search('משהו לחבר קרשים')->assertOk()->assertJsonPath('semantic', false);
        $this->assertSame([], $this->meaningAsked);
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

        return $this->call('GET', $path, server: ['HTTP_X_REGA_TIMESTAMP' => $timestamp, 'HTTP_X_REGA_SIGNATURE' => $signature]);
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
