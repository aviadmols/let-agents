<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "יש טבעת שמתאימה לצמיד?" on the page of a two-tone 14K chain-link bracelet (Rocks, 19.11) was answered with a general
 * paragraph and no ring: on a product's page the writer had that product alone. Code now finds the store's products of the
 * kind asked, in stock, for the product's audience, those sharing most with it first, and gives them to the writer beside
 * it; no product of another kind is offered as one, and a question about the product itself is left as it was.
 */
final class GoesWithTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'lat_gggggggggggggggggggggggggggggggggggggggggggggggg';

    /** @var list<array{provider: AiProviderName, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Features::override('assistant.site_scope', false);
        LoadedIndex::forget();
        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly GoesWithTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'user' => $user];

                return new ModelReply(array_shift($this->test->replies) ?? [], 700, 120);
            }
        });
        $this->app->instance(Passages::class, new class implements Passages
        {
            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                return [];
            }
        });
    }

    public function test_a_ring_to_match_the_bracelet_on_the_screen_is_the_rings_sharing_most_with_it(): void
    {
        $this->rocks();
        $this->replies = [
            ['found' => true, 'answer' => 'כן, יש לנו טבעת שהולכת יפה עם הצמיד: טבעת חוליות Jubilee, באותו מוטיב חוליות ובאותו זהב דו-גוני.', 'refs' => [],
                'picks' => [['ref' => 'p2', 'why' => 'אותו מוטיב חוליות ואותו זהב דו-גוני'], ['ref' => 'p1', 'why' => 'הצמיד']], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('יש טבעת שמתאימה לצמיד?', ['17156', 'b2'], page: true)->assertOk()->json('data');

        $writer = json_decode($this->calls[0]['user'] ?? 'null', true);
        $ids = $this->idsOf($writer);
        $this->assertSame('p1', $writer['viewing'] ?? null, 'the bracelet is still the product on the page');
        $this->assertSame(['17156', 'r1', 'r2', 'r3'], $ids, 'the rings in stock, those sharing most with the bracelet first, the silver one last');
        $this->assertNotContains('b2', $ids, 'the other bracelet on the page is no ring');
        $this->assertNotContains('e1', $ids, 'earrings are no ring, whatever they share');
        $this->assertNotContains('r4', $ids, 'a ring out of stock is not offered');
        $this->assertNotContains('r5', $ids, 'a kids\' ring is not offered beside a grown-up\'s bracelet');

        $goes = $writer['analysis']['goes_with'];
        $this->assertSame(['kind' => 'טבעת', 'with' => 'p1', 'found' => 3], array_intersect_key($goes, array_flip(['kind', 'with', 'found'])));
        $this->assertSame('p2', $goes['matches'][0]['ref']);
        foreach (['זהב דו-גוני', '14K', "וינטג'", 'חוליות', 'Jubilee'] as $shared) {
            $this->assertContains($shared, $goes['matches'][0]['shares'], 'why the first ring matches: '.$shared);
        }
        $this->assertNotContains('SALE', $goes['matches'][0]['shares'], 'a sale is no match');
        $this->assertSame([], $goes['matches'][2]['shares'], 'the silver ring shares nothing');

        $this->assertSame('answered', $data['outcome']);
        $this->assertSame(['r1'], array_column($data['picks'], 'external_id'), 'the ring is shown, not the bracelet again');
        $this->assertArrayHasKey('goes_with', json_decode($this->calls[1]['user'], true)['analysis'] ?? [], 'the checker reads what the ring shares with the bracelet');
        $this->assertCount(2, $this->calls, 'one writer, one checker: no model call added');
    }

    public function test_no_product_of_the_kind_asked_is_said_as_none_never_another_kind(): void
    {
        $this->rocks(withRings: false);
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => [], 'names' => [], 'general' => [], 'not_found' => 'לא מצאנו כרגע טבעת שמתאימה לצמיד הזה.', 'fine' => true]];

        $this->askWith('יש טבעת שמתאימה לצמיד?', ['17156', 'b2'], page: true)->assertOk();

        $writer = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['17156'], $this->idsOf($writer), 'the bracelet alone: no earring or bracelet stands in for a ring');
        $this->assertSame(['kind' => 'טבעת', 'with' => 'p1', 'found' => 0, 'matches' => []], $writer['analysis']['goes_with']);
    }

    public function test_a_question_about_the_product_itself_is_left_as_it_was(): void
    {
        $this->rocks();

        foreach (['מה המידה?', 'הצמיד הזה 14K?', 'what size is it?'] as $question) {
            $this->calls = [];
            $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => [], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true]];
            $this->askWith($question, ['17156', 'b2'], page: true)->assertOk();

            $writer = json_decode($this->calls[0]['user'], true);
            $this->assertSame(['17156', 'b2'], $this->idsOf($writer), $question.': what the page showed');
            $this->assertArrayNotHasKey('goes_with', $writer['analysis'], $question);
        }

        // and with the shop's switch off, a match asked for is too
        Features::override('assistant.goes_with', false);
        $this->calls = [];
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => [], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true]];
        $this->askWith('יש טבעת שמתאימה לצמיד?', ['17156', 'b2'], page: true)->assertOk();
        $this->assertArrayNotHasKey('goes_with', json_decode($this->calls[0]['user'], true)['analysis']);
    }

    public function test_an_english_store_finds_the_bracelet_that_goes_with_its_necklace(): void
    {
        $facets = [
            ['k' => 'type', 'l' => 'Type', 'v' => [['k' => 'ring', 'l' => 'Ring'], ['k' => 'necklace', 'l' => 'Necklace'], ['k' => 'bracelet', 'l' => 'Bracelet']]],
            ['k' => 'metal', 'l' => 'Metal', 'v' => [['k' => 'gold', 'l' => 'Gold'], ['k' => 'silver', 'l' => 'Silver']]],
        ];
        $items = [
            ['n1', 'Figaro Chain Necklace', [1, 1000], 900],
            ['x1', 'Silver Bead Bracelet', [2, 1001], 120],
            ['x2', 'Figaro Bracelet', [2, 1000], 700],
            ['x3', 'Plain Band Ring', [0, 1000], 650],
            ['x4', 'Tennis Necklace', [1, 1000], 3000],
        ];
        foreach (range(1, 6) as $i) {
            $items[] = ['f'.$i, 'Pendant Charm '.$i, [1000], 200];
        }
        $this->index($facets, $items);
        $this->replies = [
            ['found' => true, 'answer' => 'Yes, the Figaro Bracelet has the same Figaro links in gold.', 'refs' => [], 'picks' => [['ref' => 'p2', 'why' => 'the same Figaro links in gold']], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('Do you have a bracelet that goes with this?', ['n1'], page: true, locale: 'en')->assertOk()->json('data');

        $writer = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['n1', 'x2', 'x1'], $this->idsOf($writer), 'the gold Figaro bracelet first, the silver one after, no ring or necklace');
        $this->assertSame('Bracelet', $writer['analysis']['goes_with']['kind']);
        $this->assertSame(['Gold', 'Figaro'], $writer['analysis']['goes_with']['matches'][0]['shares']);
        $this->assertSame(['x2'], array_column($data['picks'], 'external_id'));
    }

    public function test_a_product_named_by_its_sku_off_its_page_is_matched_as_if_on_it(): void
    {
        $this->rocks();
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => [], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true]];

        $this->askWith('יש לכם טבעת שמתאימה למוצר עם המקט 17156?', [])->assertOk();

        $writer = json_decode($this->calls[0]['user'], true);
        $this->assertSame('p1', $writer['viewing'] ?? null, 'the product of SKU 17156 is the one the match is for');
        $this->assertSame(['17156', 'r1', 'r2', 'r3'], $this->idsOf($writer));
    }

    public function test_a_sku_names_its_product_and_a_number_before_a_measure_or_an_amount_names_none(): void
    {
        $this->product('sh1', 'שמפו לתינוק 400 מ"ל', 'שמפו עדין.', [], ['sku' => 'SH-77']);
        $this->product('c400', 'מרכך שיער', 'מרכך.', [], ['sku' => '400']);
        $this->product('c4000', 'מסרק', 'מסרק.', [], ['sku' => '4000']);
        $this->product('b17156', 'צמיד Jubilee ברוחב 4.6 מ"מ - מק"ט 17156, זהב 14K', 'צמיד.', [], ['sku' => '17156']);
        $this->product('ab', 'שעון', 'שעון.', [], ['sku' => 'AB-1234']);

        $named = fn (string $question): array => $this->inShop(fn (): array => (fn (string $q): array => $this->namedProducts($q))->call(app(AnswerSiteQuestion::class), $question));

        $this->assertSame(['b17156'], array_slice($named('יש לכם טבעת שמתאימה למוצר עם המקט 17156?'), 0, 1), 'the SKU names its product whole');
        $this->assertSame(['ab'], array_slice($named('מה המחיר של ab-1234?'), 0, 1), 'letters and digits, in any case');
        $this->assertNotContains('c400', $named('שמפו 400 מ"ל'), 'a number before a measure names nothing by its number');
        $this->assertNotContains('c4000', $named('מתנה עד 4000 ש"ח'), 'nor an amount the question keeps to');
    }

    /**
     * Rocks' rings beside its chain-link bracelet: types, metals, karats, a style, a sale and sizes as the index has them,
     * with pendants enough that a motif is a word few products say.
     */
    private function rocks(bool $withRings = true): void
    {
        $facets = [
            ['k' => 'type', 'l' => 'סוג', 'v' => [['k' => 'ring', 'l' => 'טבעת'], ['k' => 'bracelet', 'l' => 'צמיד'], ['k' => 'earrings', 'l' => 'עגילים'], ['k' => 'pendant', 'l' => 'תליון']]],
            ['k' => 'metal', 'l' => 'מתכת', 'v' => [['k' => 'yellow', 'l' => 'זהב צהוב'], ['k' => 'two_tone', 'l' => 'זהב דו-גוני'], ['k' => 'silver', 'l' => 'כסף 925']]],
            ['k' => 'karat', 'l' => 'קראט', 'v' => [['k' => '14k', 'l' => '14K'], ['k' => '18k', 'l' => '18K']]],
            ['k' => 'style', 'l' => 'סגנון', 'v' => [['k' => 'vintage', 'l' => "וינטג'"]]],
            ['k' => 'deal', 'l' => 'מבצעים', 'v' => [['k' => 'sale', 'l' => 'SALE']]],
            ['k' => 'variant_size', 'l' => 'מידה', 'v' => [['k' => '52', 'l' => '52'], ['k' => '54', 'l' => '54']]],
        ];
        $items = [
            ['17156', 'צמיד וינטאג חוליות Jubilee ברוחב 4.6 מ"מ - מק"ט 17156, זהב 14K', [1, 1001, 2000, 3000, 4000, 5000], 4200, ['sku' => '17156']],
            ['b2', 'צמיד חוליות זהב 14K', [1, 1001, 2000], 3900],
            ['e1', 'עגילי חוליות Jubilee זהב 14K', [2, 1001, 2000, 3000], 2100],
        ];
        if ($withRings) {
            array_push($items,
                ['r1', 'טבעת חוליות Jubilee זהב 14K', [0, 1001, 2000, 3000, 4000, 5001], 2600],
                ['r2', "טבעת גורמט וינטג' זהב דו גוני", [0, 1001, 2000], 1900],
                ['r3', 'טבעת כסף חלקה', [0, 1002], 300],
                ['r4', 'טבעת חוליות זהב 14K', [0, 1001, 2000, 3000], 2500, ['in_stock' => false]],
                ['r5', 'טבעת לילדים זהב דו-גוני', [0, 1001, 2000], 900],
            );
        }
        foreach (range(1, 10) as $i) {
            $items[] = ['t'.$i, 'תליון לב זהב צהוב '.$i, [3, 1000, 2000], 1200];
        }
        $this->index($facets, $items);
    }

    /**
     * The products, in the catalogue and in the index, each [external id, title, filter codes, price, overrides].
     *
     * @param  list<array<string, mixed>>  $facets
     * @param  list<array<int, mixed>>  $items
     */
    private function index(array $facets, array $items): void
    {
        $records = [];
        foreach ($items as $item) {
            [$id, $title, $codes, $price] = $item;
            $overrides = ($item[4] ?? []) + ['price' => (string) $price, 'in_stock' => true];
            $this->product((string) $id, (string) $title, (string) $title, [], $overrides);
            $records[] = ['id' => 'p:'.$id, 't' => 'product', 'title' => $title, 'kw' => '', 's' => $overrides['in_stock'] ? 1 : 0, 'pr' => (float) $price, 'f' => $codes];
        }
        $json = (string) json_encode(['v' => 1, 'items' => $records, 'facets' => $facets], JSON_UNESCAPED_UNICODE);
        $this->inShop(fn () => SearchIndex::query()->create(['shop_id' => $this->shop->id, 'hash' => md5($json), 'items' => $json, 'counts' => [], 'built_at' => now()]));
    }

    /** @return list<string> the products the writer was given, by external id, in order */
    private function idsOf(array $writer): array
    {
        $titles = array_column((array) ($writer['products'] ?? []), 'title');

        return array_values(array_map(fn (string $title): string => (string) $this->inShop(fn () => CatalogProduct::query()->where('title', $title)->value('external_id')), $titles));
    }

    private function askWith(string $question, array $products, bool $page = false, string $locale = 'he'): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => 'anon-visitor1234567890goes', 'locale' => $locale, 'products' => $products] + ($page ? ['page' => 1] : []), JSON_UNESCAPED_UNICODE));
    }
}
