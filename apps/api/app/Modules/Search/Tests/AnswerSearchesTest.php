<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Contracts\AnswerSearches;
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
 * Under an answer about products, a button into the store's search for what was asked, its filters chosen (as Aviad asked
 * for Rocks, 19.11): "יש לכם טבעת יהלום בזהב לבן עד 3000?" is the search "טבעת · יהלום · זהב לבן" up to ₪3,000, and a ring
 * asked to go with the bracelet on the screen is the rings sharing its values. Read in code off the index, no model call
 * added; none under a question of the store's; at most assistant.answer_searches_max, each showing at least
 * assistant.answer_searches_min products and more than the answer's own. Off unless the shop has it on.
 */
final class AnswerSearchesTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'lat_ssssssssssssssssssssssssssssssssssssssssssssssss';

    /** @var list<array{system: string, user: string}> */
    public array $calls = [];

    /** @var list<string> the titles the writer picks, of the products it was given */
    public array $pick = [];

    protected function setUp(): void
    {
        parent::setUp();

        Features::override('assistant.site_scope', false);
        LoadedIndex::forget();
        $this->buildShop();
        Features::override('assistant.answer_searches', true, $this->shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));

        $test = $this;
        // the writer picks the products named in $pick from those it was given; the checker passes what it reads
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly AnswerSearchesTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['system' => $system, 'user' => $user];
                $input = json_decode($user, true);
                if (is_array($input) && isset($input['products']) && ! isset($input['answer'])) {
                    $picks = [];
                    foreach ((array) $input['products'] as $p) {
                        if (in_array($p['title'] ?? null, $this->test->pick, true)) {
                            $picks[] = ['ref' => $p['ref'], 'why' => 'מתאים'];
                        }
                    }

                    return new ModelReply(['found' => true, 'answer' => 'כן, יש לנו כאלה.', 'refs' => [], 'picks' => $picks, 'names' => [], 'general' => [], 'not_found' => '', 'fine' => true], 700, 120);
                }

                return new ModelReply(['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true], 300, 40);
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

    public function test_a_question_for_a_kind_with_its_values_is_one_search_with_those_filters_chosen(): void
    {
        $this->jewellery();
        $this->pick = ['טבעת יהלום זהב לבן קלאסית'];

        $data = $this->ask('יש לכם טבעת יהלום בזהב לבן?', ['w1', 'w2', 'w3'])->assertOk()->json('data');

        $this->assertSame('answered', $data['outcome']);
        $this->assertSame(['w1'], array_column($data['picks'], 'external_id'));
        $this->assertCount(1, $data['searches']);
        $search = $data['searches'][0];
        $this->assertSame('טבעת · יהלום · זהב לבן', $search['label'], 'the kind, then the values as the question names them');
        $this->assertSame('טבעת יהלום זהב לבן', $search['query']);
        $this->assertSame([['k' => 'type', 'v' => 'ring', 'l' => 'טבעת'], ['k' => 'stone', 'v' => 'diamond', 'l' => 'יהלום'], ['k' => 'metal', 'v' => 'white', 'l' => 'זהב לבן']], $search['chips']);
        $this->assertNull($search['price']);
        $this->assertSame(4, $search['count'], 'the four in stock: never the sold-out one, the yellow one, the one a check refused, nor earrings');
        $this->assertArrayNotHasKey('related', $data, 'the search stands where the chips of the store\'s groups would');
        $this->assertCount(2, $this->calls, 'one writer, one checker: no model call added');

        // off: no button, the answer as before
        Features::override('assistant.answer_searches', false, $this->shop->id);
        $this->calls = [];
        $off = $this->ask('יש לכם טבעת יהלום בזהב לבן?', ['w1', 'w2', 'w3'])->assertOk()->json('data');
        $this->assertArrayNotHasKey('searches', $off);
        $this->assertSame(['w1'], array_column($off['picks'], 'external_id'));
    }

    public function test_a_price_asked_answered_as_a_grid_is_the_same_search_filterable(): void
    {
        $this->jewellery();

        // more than three in the price: every one of them, shown as a grid without filters (computed, no model)
        $data = $this->ask('יש לכם טבעת יהלום בזהב לבן עד 3000?', ['w1', 'w2', 'w3'])->assertOk()->json('data');

        $this->assertNotEmpty($data['set']['ids']);
        $this->assertCount(1, $data['searches']);
        $search = $data['searches'][0];
        $this->assertSame('טבעת · יהלום · זהב לבן', $search['label'], 'the price apart: the drawer writes it in the store\'s currency');
        $this->assertEquals(['min' => null, 'max' => 3000], $search['price']);
        $this->assertSame(3, $search['count'], 'the three in stock inside the price: never the dear one, nor the one a check refused');
        $this->assertSame([], $this->calls, 'no model call');
    }

    public function test_a_ring_to_go_with_the_bracelet_is_the_rings_sharing_its_values_together_and_one_apart(): void
    {
        Features::override('assistant.goes_with', true, $this->shop->id);
        $this->rocks();
        $this->pick = ['טבעת חוליות Jubilee זהב 14K'];

        $data = $this->ask('יש טבעת שמתאימה לצמיד?', ['17156', 'b2'], page: true)->assertOk()->json('data');

        $this->assertSame(['r1'], array_column($data['picks'], 'external_id'));
        $this->assertSame(['טבעת · זהב דו-גוני · 14K', "טבעת · וינטג'"], array_column($data['searches'], 'label'), 'every value the ring shares with the bracelet that still leaves a search, then the one left out on its own');
        $this->assertSame([3, 2], array_column($data['searches'], 'count'), 'in stock: the two-tone 14K rings, the vintage rings');
        $this->assertSame(['type', 'metal', 'karat'], array_column($data['searches'][0]['chips'], 'k'), 'never the sale or the size the bracelet is filed under');
        $this->assertNull($data['searches'][0]['price']);
        $this->assertCount(2, $this->calls, 'one writer, one checker: no model call added');

        // "…לצמידים האלה" points at the product of the page, not at results shown: the same searches (Rocks, 19.11)
        $this->calls = [];
        $these = $this->ask('יש לך טבעת שיכולה להתאים לצמידים האלה?', ['17156', 'b2'], page: true)->assertOk()->json('data');
        $this->assertSame(['r1'], array_column($these['picks'], 'external_id'));
        $this->assertSame(['טבעת · זהב דו-גוני · 14K', "טבעת · וינטג'"], array_column($these['searches'] ?? [], 'label'));
    }

    public function test_a_match_is_searched_by_what_the_products_are_never_by_their_price_category(): void
    {
        // the earrings are filed only by the store's categories and tags (Rocks, 19.11): a karat tag is what they share with
        // the necklaces, "מחיר מלא" is where all of them stand
        $facets = [
            ['k' => 'type', 'l' => 'סוג', 'v' => [['k' => 'necklace', 'l' => 'שרשרת'], ['k' => 'earrings', 'l' => 'עגילים']]],
            ['k' => 'category', 'l' => 'קטגוריה', 'v' => [['k' => 'full', 'l' => 'מחיר מלא'], ['k' => 'yellow_earrings', 'l' => 'עגילים זהב צהוב']]],
            ['k' => 'tag:gold_karat', 'l' => 'קראט', 'v' => [['k' => '14', 'l' => 'זהב 14 קארט'], ['k' => '18', 'l' => 'זהב 18 קארט']]],
        ];
        $this->index($facets, [
            ['a1', 'עגילי חישוק חלולים', [1000, 1001, 2000], 2400],
            ['n1', 'שרשרת חוליות חלולה', [0, 1000, 2000], 3100],
            ['n2', 'שרשרת וינטג', [0, 1000, 2000], 2900],
            ['n3', 'שרשרת חבל', [0, 1000, 2000], 2500],
            ['n4', 'שרשרת חוליות 18K', [0, 1000, 2001], 4200],
            ['n5', 'שרשרת דקה', [0, 1000], 900],
        ]);

        $searches = $this->inShop(fn (): array => app(AnswerSearches::class)->searches($this->shop->id, 'יש לך שרשרת שיכולה להתאים לעגילים האלה?', ['n1', 'n2'], ['anchor' => 'a1', 'ids' => ['n1', 'n2']], 3, 2));

        $this->assertSame(['שרשרת · זהב 14 קארט'], array_column($searches, 'label'), 'the karat, never the price category every necklace stands in');
        $this->assertSame(3, $searches[0]['count']);
    }

    public function test_a_question_of_the_stores_gets_no_search(): void
    {
        $this->jewellery();
        $this->pick = ['טבעת יהלום זהב לבן קלאסית'];

        $data = $this->ask('מה מדיניות ההחזרות?', ['w1', 'w2'])->assertOk()->json('data');

        $this->assertArrayNotHasKey('searches', $data);
    }

    public function test_at_most_the_shops_number_and_each_with_enough_products_beyond_the_answers_own(): void
    {
        $this->rocks();
        $searches = fn (array $picks, ?array $goesWith, string $question = 'יש טבעת שמתאימה לצמיד?'): array => $this->inShop(fn (): array => app(AnswerSearches::class)
            ->searches($this->shop->id, $question, $picks, $goesWith, (int) Settings::get('assistant.answer_searches_max', $this->shop->id), (int) Settings::get('assistant.answer_searches_min', $this->shop->id)));
        $anchor = ['anchor' => '17156', 'ids' => ['r1', 'r2']];

        $this->assertCount(2, $searches(['r1'], $anchor));
        Settings::set('assistant.answer_searches_max', 1, $this->shop->id);
        $this->assertSame(['טבעת · זהב דו-גוני · 14K'], array_column($searches(['r1'], $anchor), 'label'), 'the shop\'s number, the best first');
        Settings::set('assistant.answer_searches_max', 3, $this->shop->id);

        // a search of fewer products than the shop's least is not offered: three vintage rings asked, two in stock
        Settings::set('assistant.answer_searches_min', 3, $this->shop->id);
        $this->assertSame(['טבעת · זהב דו-גוני · 14K'], array_column($searches(['r1'], $anchor), 'label'));
        Settings::set('assistant.answer_searches_min', 2, $this->shop->id);

        // nor one that shows only what the answer already shows: both vintage rings picked, the vintage rings are no search;
        // the two-tone rings are, and the 14K rings, the same three, are not offered again
        $this->assertSame(['טבעת · זהב דו-גוני'], array_column($searches(['r1', 'r6'], $anchor), 'label'));
        // ...unless the answer showed them as a grid without filters: the same products, filterable
        $this->assertContains("טבעת · וינטג'", array_column($this->inShop(fn (): array => app(AnswerSearches::class)->searches($this->shop->id, 'יש טבעת שמתאימה לצמיד?', ['r1', 'r6'], $anchor, 3, 2, false)), 'label'));

        // a question that names nothing the store files by, nor a price, is no search
        $this->assertSame([], $searches(['r1'], null, 'מה הכי מומלץ אצלכם?'));
    }

    /** A jewellery store: rings and earrings, white and yellow gold, diamonds. */
    private function jewellery(): void
    {
        $facets = [
            ['k' => 'type', 'l' => 'סוג', 'v' => [['k' => 'ring', 'l' => 'טבעת'], ['k' => 'earrings', 'l' => 'עגילים']]],
            ['k' => 'metal', 'l' => 'מתכת', 'v' => [['k' => 'white', 'l' => 'זהב לבן'], ['k' => 'yellow', 'l' => 'זהב צהוב']]],
            ['k' => 'stone', 'l' => 'אבן', 'v' => [['k' => 'diamond', 'l' => 'יהלום']]],
        ];
        $this->index($facets, [
            ['w1', 'טבעת יהלום זהב לבן קלאסית', [0, 1000, 2000], 2500],
            ['w2', 'טבעת יהלום זהב לבן עדינה', [0, 1000, 2000], 2800],
            ['w3', 'טבעת סוליטר זהב לבן', [0, 1000, 2000], 2900],
            ['w4', 'טבעת יהלום זהב לבן יוקרתית', [0, 1000, 2000], 5000],
            ['w5', 'טבעת יהלום זהב לבן שאזלה', [0, 1000, 2000], 2000, ['in_stock' => false]],
            ['w6', 'טבעת יהלום זהב צהוב', [0, 1001, 2000], 2000],
            ['w7', 'טבעת זהב לבן עם אבן', [0, 1000, 2000], 2000, [], [2000]],
            ['e1', 'עגילי יהלום זהב לבן', [1, 1000, 2000], 2000],
        ]);
    }

    /** Rocks' bracelet and rings, as GoesWithTest, with a second vintage ring. */
    private function rocks(): void
    {
        $facets = [
            ['k' => 'type', 'l' => 'סוג', 'v' => [['k' => 'ring', 'l' => 'טבעת'], ['k' => 'bracelet', 'l' => 'צמיד'], ['k' => 'earrings', 'l' => 'עגילים']]],
            ['k' => 'metal', 'l' => 'מתכת', 'v' => [['k' => 'yellow', 'l' => 'זהב צהוב'], ['k' => 'two_tone', 'l' => 'זהב דו-גוני'], ['k' => 'silver', 'l' => 'כסף 925']]],
            ['k' => 'karat', 'l' => 'קראט', 'v' => [['k' => '14k', 'l' => '14K'], ['k' => '18k', 'l' => '18K']]],
            ['k' => 'style', 'l' => 'סגנון', 'v' => [['k' => 'vintage', 'l' => "וינטג'"]]],
            ['k' => 'deal', 'l' => 'מבצעים', 'v' => [['k' => 'sale', 'l' => 'SALE']]],
            ['k' => 'variant_size', 'l' => 'מידה', 'v' => [['k' => '52', 'l' => '52'], ['k' => '54', 'l' => '54']]],
        ];
        $this->index($facets, [
            ['17156', 'צמיד וינטאג חוליות Jubilee ברוחב 4.6 מ"מ - מק"ט 17156, זהב 14K', [1, 1001, 2000, 3000, 4000, 5000], 4200, ['sku' => '17156']],
            ['b2', 'צמיד חוליות זהב 14K', [1, 1001, 2000], 3900],
            ['e1', 'עגילי חוליות Jubilee זהב 14K', [2, 1001, 2000, 3000], 2100],
            ['r1', 'טבעת חוליות Jubilee זהב 14K', [0, 1001, 2000, 3000, 4000, 5001], 2600],
            ['r2', "טבעת גורמט וינטג' זהב דו גוני", [0, 1001, 2000], 1900],
            ['r3', 'טבעת כסף חלקה', [0, 1002], 300],
            ['r4', 'טבעת חוליות זהב 14K', [0, 1001, 2000, 3000], 2500, ['in_stock' => false]],
            ['r5', 'טבעת לילדים זהב דו-גוני', [0, 1001, 2000], 900],
            ['r6', 'טבעת וינטג׳ זהב צהוב', [0, 1000, 3000], 1700],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $facets
     * @param  list<array<int, mixed>>  $items  id, title, filter codes, price, product overrides, codes a check refused
     */
    private function index(array $facets, array $items): void
    {
        $records = [];
        foreach ($items as $item) {
            [$id, $title, $codes, $price] = $item;
            $overrides = ($item[4] ?? []) + ['price' => (string) $price, 'in_stock' => true];
            $this->product((string) $id, (string) $title, (string) $title, [], $overrides);
            $records[] = ['id' => 'p:'.$id, 't' => 'product', 'title' => $title, 'kw' => '', 's' => $overrides['in_stock'] ? 1 : 0, 'pr' => (float) $price, 'f' => $codes]
                + (($item[5] ?? []) === [] ? [] : ['no' => $item[5]]);
        }
        $json = (string) json_encode(['v' => 1, 'items' => $records, 'facets' => $facets], JSON_UNESCAPED_UNICODE);
        $this->inShop(fn () => SearchIndex::query()->create(['shop_id' => $this->shop->id, 'hash' => md5($json), 'items' => $json, 'counts' => [], 'built_at' => now()]));
    }

    /** @param  list<string>  $products */
    private function ask(string $question, array $products, bool $page = false): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => 'anon-visitor1234567890srch', 'locale' => 'he', 'products' => $products] + ($page ? ['page' => 1] : []), JSON_UNESCAPED_UNICODE));
    }
}
