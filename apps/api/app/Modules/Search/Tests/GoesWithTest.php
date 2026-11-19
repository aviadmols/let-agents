<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Models\AssistantGoesWithRules;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\RelationKind;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentProductRelation;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The goes-with mechanism the answerer routes to, and only to: a product of another kind to go with the one on the screen
 * (or named by its SKU), a match by look ("יש טבעת שמתאימה לצמיד?", Rocks 19.11: answered with a paragraph and no ring) or
 * what is used with it ("מה עוד צריך למקדחה?"), found by code at question time from the store's own pairings and the
 * index, and handed to the answer prompt. Every other question goes through today's flow untouched, byte for byte. The
 * store focuses it: sources, kinds that may be paired, what is never offered, how many are shown.
 */
final class GoesWithTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'lat_gggggggggggggggggggggggggggggggggggggggggggggggg';

    /** @var list<array{provider: AiProviderName, system: string, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    private ?string $vocabularyId = null;

    protected function setUp(): void
    {
        parent::setUp();

        Features::override('assistant.site_scope', false);
        LoadedIndex::forget();
        $this->buildShop();
        // off by default: a store turns it on from its settings page once its answers were checked
        Features::override('assistant.goes_with', true, $this->shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly GoesWithTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'system' => $system, 'user' => $user];

                return new ModelReply(array_shift($this->test->replies) ?? ['found' => false, 'answer' => '', 'refs' => [], 'picks' => [], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true], 700, 120);
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

        $writer = json_decode($this->calls[0]['user'], true);
        $ids = $this->idsOf($writer);
        $this->assertSame('p1', $writer['viewing'] ?? null, 'the bracelet is still the product on the page');
        $this->assertSame(['17156', 'r1', 'r2', 'r3'], $ids, 'the rings in stock, those sharing most with the bracelet first, the silver one last');
        $this->assertNotContains('b2', $ids, 'the other bracelet on the page is no ring');
        $this->assertNotContains('e1', $ids, 'earrings are no ring, whatever they share');
        $this->assertNotContains('r4', $ids, 'a ring out of stock is not offered');
        $this->assertNotContains('r5', $ids, 'a kids\' ring is not offered beside a grown-up\'s bracelet');

        $goes = $writer['analysis']['goes_with'];
        $this->assertSame(['kind' => 'טבעת', 'with' => 'p1', 'found' => 3], array_intersect_key($goes, array_flip(['kind', 'with', 'found'])));
        $this->assertArrayNotHasKey('complement', $goes, 'a match by look, not what is used with it');
        $this->assertSame('p2', $goes['matches'][0]['ref']);
        foreach (['זהב דו-גוני', '14K', "וינטג'", 'חוליות', 'Jubilee'] as $shared) {
            $this->assertContains($shared, $goes['matches'][0]['shares'], 'why the first ring matches: '.$shared);
        }
        $this->assertNotContains('SALE', $goes['matches'][0]['shares'], 'a sale is no match');
        $this->assertSame([], $goes['matches'][2]['shares'], 'the silver ring shares nothing');

        $this->assertSame(file_get_contents(dirname(__DIR__, 2).'/Assistant/Prompts/goes_with_answer.v1.md'), $this->calls[0]['system'], 'written by the mechanism\'s own prompt');
        $this->assertSame('answered', $data['outcome']);
        $this->assertSame(['r1'], array_column($data['picks'], 'external_id'), 'the ring is shown, not the bracelet again');
        $this->assertArrayHasKey('goes_with', json_decode($this->calls[1]['user'], true)['analysis'] ?? [], 'the checker reads what the ring shares with the bracelet');
        $this->assertCount(2, $this->calls, 'one writer, one checker: no model call added');
    }

    public function test_no_product_of_the_kind_asked_is_said_as_none_never_another_kind(): void
    {
        $this->rocks(withRings: false);

        $this->askWith('יש טבעת שמתאימה לצמיד?', ['17156', 'b2'], page: true)->assertOk();

        $writer = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['17156'], $this->idsOf($writer), 'the bracelet alone: no earring or bracelet stands in for a ring');
        $this->assertSame(['kind' => 'טבעת', 'with' => 'p1', 'found' => 0, 'matches' => []], $writer['analysis']['goes_with']);
    }

    /**
     * Every other question goes through today's flow untouched: what the writer is given, the prompt it is given with and
     * the picks are the same bytes with the mechanism on and off. A price question, a store question, a question about the
     * product itself.
     */
    public function test_every_other_question_is_answered_exactly_as_before_with_the_mechanism_on_and_off(): void
    {
        $this->rocks();
        $released = (string) file_get_contents(dirname(__DIR__, 2).'/Assistant/Prompts/answer_site.v'.AnswerSiteQuestion::PROMPT_VERSION.'.md');
        $reply = fn (): array => ['found' => true, 'answer' => 'תשובה.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'הצמיד']], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true];
        $check = ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true];

        foreach (['כמה עולה הצמיד?', 'מה מדיניות ההחזרות?', 'מה המידה של הצמיד?', 'what size is it?'] as $question) {
            $seen = [];
            foreach ([true, false] as $on) {
                Features::override('assistant.goes_with', $on, $this->shop->id);
                $this->calls = [];
                $this->replies = [$reply(), $check];
                $data = $this->askWith($question, ['17156', 'b2'], page: true)->assertOk()->json('data');
                $input = json_decode($this->calls[0]['user'], true);
                $this->assertArrayNotHasKey('goes_with', $input['analysis'] ?? [], $question);
                $this->assertSame($released, $this->calls[0]['system'], $question.': the released writer, on or off');
                $snapshot = app(AnswerSiteQuestion::class)->snapshot($this->shop->id, $question, ['17156', 'b2']);
                $seen[] = ['input' => $this->calls[0]['user'], 'snapshot' => $snapshot['input'], 'picks' => array_column($data['picks'] ?? [], 'external_id'), 'outcome' => $data['outcome']];
                // a saved answer would answer the second round without a model: asked afresh each time
                $this->inShop(fn () => AssistantAnswer::query()->delete());
            }
            $this->assertSame($seen[0], $seen[1], $question.': the same bytes with the mechanism on and off');
        }
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

    /** A hardware store, no map anywhere: what else a drill needs is what of other kinds shares its system (Makita) first. */
    public function test_a_hardware_store_offers_the_bits_and_screws_of_the_drills_own_system_first(): void
    {
        $this->hardware();

        $this->askWith('מה עוד צריך למקדחה?', ['d1'], page: true)->assertOk();

        $writer = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['d1', 'b1', 's1'], $this->idsOf($writer), 'the Makita bits first (nearer in price), then the Makita screws; no drill, and nothing that shares nothing (the Bosch bits, the glue, a bit out of stock)');
        $goes = $writer['analysis']['goes_with'];
        $this->assertSame('', $goes['kind'], 'no kind was named: whatever other kinds go with it');
        $this->assertTrue($goes['complement']);
        $this->assertSame(['Makita'], $goes['matches'][0]['shares'], 'why: the same system');
        $this->assertSame(['Makita'], $goes['matches'][1]['shares']);
    }

    /** A fashion store: what goes with the dress is what of other kinds shares its colour, the nearer in price first. */
    public function test_a_fashion_store_offers_what_goes_with_the_dress_by_its_colour(): void
    {
        $facets = [
            ['k' => 'type', 'l' => 'Type', 'v' => [['k' => 'dress', 'l' => 'Dress'], ['k' => 'belt', 'l' => 'Belt'], ['k' => 'bag', 'l' => 'Bag'], ['k' => 'shoes', 'l' => 'Shoes']]],
            ['k' => 'variant_colour', 'l' => 'Colour', 'v' => [['k' => 'black', 'l' => 'Black'], ['k' => 'red', 'l' => 'Red']]],
        ];
        $items = [
            ['dr1', 'Wrap Midi Dress', [0, 1000], 320, [], 'dress'],
            ['be1', 'Slim Leather Belt', [1, 1000], 60, [], 'belt'],
            ['be2', 'Wide Red Belt', [1, 1001], 70, [], 'belt'],
            ['ba1', 'Evening Clutch Bag', [2, 1000], 140, [], 'bag'],
            ['sh1', 'Court Shoes', [3, 1000], 190, [], 'shoes'],
            ['dr2', 'Shirt Dress', [0, 1000], 280, [], 'dress'],
        ];
        $this->index($facets, $items, types: ['dress' => 'Dress', 'belt' => 'Belt', 'bag' => 'Bag', 'shoes' => 'Shoes']);

        $this->askWith('What goes with this dress?', ['dr1'], page: true, locale: 'en')->assertOk();

        $writer = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['dr1', 'sh1', 'ba1', 'be1'], $this->idsOf($writer), 'the black shoes, bag and belt, the nearer in price first; never another dress, and not the red belt that shares nothing');
        $this->assertSame('', $writer['analysis']['goes_with']['kind']);
        $this->assertTrue($writer['analysis']['goes_with']['complement']);
        $this->assertSame(['Black'], $writer['analysis']['goes_with']['matches'][0]['shares']);
    }

    public function test_nothing_of_another_kind_shares_anything_is_said_as_none(): void
    {
        $this->hardware();

        $this->askWith('מה עוד צריך לזה?', ['g1'], page: true)->assertOk();

        $writer = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['g1'], $this->idsOf($writer), 'the glue alone: no product that only happens to be of another kind stands in');
        $this->assertSame(['kind' => '', 'with' => 'p1', 'found' => 0, 'matches' => [], 'complement' => true], $writer['analysis']['goes_with']);
    }

    public function test_the_stores_own_pairing_is_offered_first_where_it_keeps_relations(): void
    {
        $this->hardware();
        Features::override('enrichment.relations', true, $this->shop->id);
        $this->relate('d1', 'g1');

        $this->askWith('מה עוד צריך למקדחה?', ['d1'], page: true)->assertOk();

        $writer = json_decode($this->calls[0]['user'], true);
        $this->assertSame(['d1', 'g1', 'b1', 's1'], $this->idsOf($writer), 'what the store itself pairs with the drill first, though the glue shares nothing else');
        $this->assertSame(['החנות מציעה אותם יחד'], $writer['analysis']['goes_with']['matches'][0]['shares']);

        // the store turns that source off: the glue is no candidate
        Settings::set('assistant.goes_with_use_relations', false, $this->shop->id);
        $this->calls = [];
        $this->askWith('מה עוד צריך למקדחה?', ['d1'], page: true)->assertOk();
        $this->assertSame(['d1', 'b1', 's1'], $this->idsOf(json_decode($this->calls[0]['user'], true)));
    }

    public function test_the_store_focuses_it_by_pairs_of_kinds_and_what_it_never_offers(): void
    {
        $this->hardware();

        // never a Bosch, and never screws with a drill: the Makita bits alone
        $this->inShop(fn () => AssistantGoesWithRules::query()->create(['shop_id' => $this->shop->id,
            'pairs' => [['a' => 'screw', 'b' => 'drill', 'allow' => false]], 'never' => [['scope' => 'vendor', 'value' => 'bosch']]]));
        $this->askWith('מה עוד צריך למקדחה?', ['d1'], page: true)->assertOk();
        $this->assertSame(['d1', 'b1'], $this->idsOf(json_decode($this->calls[0]['user'], true)));

        // allowed kinds named for the drill: only those
        $this->inShop(fn () => AssistantGoesWithRules::query()->sole()->update(['pairs' => [['a' => 'drill', 'b' => 'screw', 'allow' => true]], 'never' => []]));
        $this->calls = [];
        $this->askWith('מה עוד צריך למקדחה?', ['d1'], page: true)->assertOk();
        $this->assertSame(['d1', 's1'], $this->idsOf(json_decode($this->calls[0]['user'], true)));

        // and at most as many cards as the store shows
        $this->inShop(fn () => AssistantGoesWithRules::query()->delete());
        Settings::set('assistant.goes_with_max_picks', 1, $this->shop->id);
        $this->calls = [];
        $this->replies = [
            ['found' => true, 'answer' => 'כדאי להוסיף מקדחים וברגים.', 'refs' => [], 'picks' => [['ref' => 'p2', 'why' => 'מקדחים'], ['ref' => 'p3', 'why' => 'ברגים']], 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $data = $this->askWith('מה עוד צריך למקדחה?', ['d1'], page: true)->assertOk()->json('data');
        $this->assertSame(['b1'], array_column($data['picks'], 'external_id'));
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

    /** Gueta's drills, bits, screws and glue, with their kinds approved and the vendors the store writes on them. */
    private function hardware(): void
    {
        $facets = [
            ['k' => 'type', 'l' => 'סוג', 'v' => [['k' => 'drill', 'l' => 'מקדחה'], ['k' => 'drill_bit', 'l' => 'מקדח'], ['k' => 'screw', 'l' => 'בורג'], ['k' => 'glue', 'l' => 'דבק']]],
            ['k' => 'brand', 'l' => 'מותג', 'v' => [['k' => 'makita', 'l' => 'Makita'], ['k' => 'bosch', 'l' => 'Bosch']]],
        ];
        $items = [
            ['d1', 'מקדחה רוטטת 18V', [0, 1000], 800, ['brand' => 'Makita'], 'drill'],
            ['d2', 'מקדחה רוטטת מקצועית', [0, 1001], 900, ['brand' => 'Bosch'], 'drill'],
            ['b1', 'סט מקדחים לבטון ולעץ', [1, 1000], 400, ['brand' => 'Makita'], 'drill_bit'],
            ['b2', 'מקדח לבטון', [1, 1001], 60, ['brand' => 'Bosch'], 'drill_bit'],
            ['b3', 'סט מקדחים לעץ', [1, 1000], 350, ['brand' => 'Makita', 'in_stock' => false], 'drill_bit'],
            ['s1', 'ברגים לדק', [2, 1000], 40, ['brand' => 'Makita'], 'screw'],
            ['g1', 'דבק עץ', [3], 30, [], 'glue'],
        ];
        $this->index($facets, $items, types: ['drill' => 'מקדחה', 'drill_bit' => 'מקדח', 'screw' => 'בורג', 'glue' => 'דבק']);
    }

    /**
     * The products, in the catalogue and in the index, each [external id, title, filter codes, price, overrides, type key],
     * and, where kinds are given, the vocabulary that names them with each product's approved type.
     *
     * @param  list<array<string, mixed>>  $facets
     * @param  list<array<int, mixed>>  $items
     * @param  array<string, string>  $types  type key => label
     */
    private function index(array $facets, array $items, array $types = []): void
    {
        if ($types !== []) {
            $this->vocabularyId = $this->inShop(fn (): string => EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'store', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'store', 'name' => ['he' => 'חנות', 'en' => 'Store'], 'root_category_external_id' => '*',
                    'product_types' => array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => ['he' => $label, 'en' => $label]], array_keys($types), $types),
                    'attributes' => []]])->id);
        }
        $records = [];
        foreach ($items as $item) {
            [$id, $title, $codes, $price] = $item;
            $overrides = ($item[4] ?? []) + ['price' => (string) $price, 'in_stock' => true];
            $product = $this->product((string) $id, (string) $title, (string) $title, [], $overrides);
            if (isset($item[5]) && $this->vocabularyId !== null) {
                $this->inShop(fn () => EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $this->vocabularyId, 'kind' => FactKind::Type, 'key' => 'type',
                    'value_text' => (string) $item[5], 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 't'.$id]));
            }
            $records[] = ['id' => 'p:'.$id, 't' => 'product', 'title' => $title, 'kw' => '', 's' => $overrides['in_stock'] ? 1 : 0, 'pr' => (float) $price, 'f' => $codes];
        }
        $json = (string) json_encode(['v' => 1, 'items' => $records, 'facets' => $facets], JSON_UNESCAPED_UNICODE);
        $this->inShop(fn () => SearchIndex::query()->create(['shop_id' => $this->shop->id, 'hash' => md5($json), 'items' => $json, 'counts' => [], 'built_at' => now()]));
    }

    /** The store pairs two products itself (a cross-sell), as ComputeProductRelations keeps it. */
    private function relate(string $product, string $related): void
    {
        $ids = $this->inShop(fn () => CatalogProduct::query()->whereIn('external_id', [$product, $related])->pluck('id', 'external_id'));
        $this->inShop(fn () => EnrichmentProductRelation::query()->create(['shop_id' => $this->shop->id, 'product_id' => $ids[$product], 'related_product_id' => $ids[$related],
            'kind' => RelationKind::Complement, 'source' => 'merchant', 'score' => 150, 'reasons' => ['merchant' => 'cross_sell'], 'computed_at' => now()]));
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
