<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Console\ContextEvalCommand;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\AnswerContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the writing model is given in dynamic context: the products and passages the question
 * needs, the fields its kind asks about, never the order changed, and never less than the first
 * products shown. Full context is what it was. Jewelry, as on Rocks and Gold.
 */
final class DynamicContextTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'lat_dddddddddddddddddddddddddddddddddddddddddddddddd';

    /** @var list<array{provider: AiProviderName, model: string, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    /** @var list<array<string, mixed>> what the fake search finds near any question */
    public array $passages = [];

    /** @var array<string, float> product external id => how near the question by meaning */
    public array $near = [];

    /** The products shown for "טבעת זהב עם אבן אדומה באמצע", in the order shown. */
    private const SHOWN = ['r2', 'r3', 'r5', 'r1', 'r6', 'r8', 'r7', 'r4'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));

        foreach ([
            'r1' => ['טבעת זהב צהוב עם אבן אדומה מרכזית', 0.82],
            'r2' => ['טבעת זהב לבן סוליטר יהלום', 0.55],
            'r3' => ['עגילי פנינה', 0.30],
            'r4' => ['טבעת גרנט אדומה וינטג', 0.80],
            'r5' => ['צמיד טניס יהלומים', 0.25],
            'r6' => ['שרשרת זהב', 0.22],
            'r7' => ['טבעת רובי אדומה', 0.78],
            'r8' => ['תליון לב כסף', 0.20],
        ] as $id => [$title, $near]) {
            $this->product($id, $title, 'תכשיט.', [], ['sku' => 'SKU-'.strtoupper($id), 'brand' => 'Rocks', 'payload' => [
                'title' => $title, 'short_description' => 'תכשיט במידות 6.5 ו-7.', 'description' => '', 'categories' => [], 'meta' => [],
                'attributes' => [['name' => 'מידה', 'values' => ['6.5', '7'], 'used_for_variations' => true]],
                'dimensions' => ['weight' => '2.4', 'weight_unit' => 'g'],
            ]]);
            $this->near[$id] = $near;
        }

        $this->passages = [
            ['source' => 'content', 'source_id' => 'k1', 'external_id' => null, 'title' => 'משלוחים והחזרות', 'text' => str_repeat('משלוח חינם לכל הארץ. ', 60), 'similarity' => 1.0, 'kind' => 'knowledge'],
            ['source' => 'content', 'source_id' => 'c1', 'external_id' => null, 'title' => 'מדריך טבעות', 'text' => 'איך בוחרים טבעת.', 'similarity' => 0.70],
            ['source' => 'content', 'source_id' => 'c2', 'external_id' => null, 'title' => 'ניקוי תכשיטים', 'text' => 'ניקוי.', 'similarity' => 0.40],
            ['source' => 'product', 'source_id' => 'x', 'external_id' => 'r6', 'title' => 'שרשרת זהב', 'text' => 'שרשרת.', 'similarity' => 0.50],
        ];

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly DynamicContextTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'model' => $model, 'user' => $user];

                return new ModelReply(array_shift($this->test->replies) ?? [], 900, 150);
            }
        });
        $this->app->instance(Passages::class, new class($test) implements Passages
        {
            public function __construct(private readonly DynamicContextTest $test) {}

            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                // Asked for products only: the products' own nearness, as the context scores them.
                if ($sources === ['product']) {
                    return array_map(fn (string $id, float $s): array => ['source' => 'product', 'source_id' => $id, 'external_id' => $id, 'title' => $id, 'text' => '', 'similarity' => $s], array_keys($this->test->near), $this->test->near);
                }

                return array_slice(array_values(array_filter($this->test->passages, fn (array $p): bool => ($p['kind'] ?? null) === null)), 0, $limit);
            }
        });
    }

    /** @return array<string, array{string, string}> */
    public static function questions(): array
    {
        return [
            'the cheapest' => ['איזו טבעת הכי זולה?', 'superlative'],
            'a budget' => ['טבעת זהב עד 2000', 'price_range'],
            'a price' => ['כמה עולה הטבעת עם הרובי?', 'price_exact'],
            'shipping' => ['כמה זמן לוקח משלוח?', 'store'],
            'resizing is the store\x27s service, not a size' => ['אפשר להגדיל טבעת?', 'store'],
            'a size' => ['באיזו מידה יש את טבעת הגרנט?', 'size'],
            'in stock' => ['הטבעת במלאי?', 'stock'],
            'a gift' => ['מה מתאים כמתנה לאמא?', 'recommendation'],
            'a stone' => ['טבעת זהב עם אבן אדומה באמצע', 'attribute'],
            'nothing known' => ['ספרו לי עליכם', 'other'],
            'a plural with its final letter inside: יהלום in יהלומים' => ['עגילים צמודים משובצים יהלומים', 'attribute'],
            'a verb: להחזיר' => ['אפשר להחזיר תכשיט שקניתי?', 'store'],
            'a verb: מבטלים' => ['איך מבטלים הזמנה?', 'store'],
            'the construct form: מידת' => ['איך יודעים מה מידת הטבעת שלי?', 'size'],
            'a recommendation with a price is a recommendation' => ['מה מתאים כמתנה לאמא עד 1500?', 'recommendation'],
            'a comparison' => ['מה ההבדל בין זהב 14K לזהב 18K?', 'comparison'],
            'a gift without the word' => ['משהו עם יהלום לא יקר לחברה שלי', 'recommendation'],
            'shipping without the word' => ['מתי זה מגיע אם מזמינים היום', 'store'],
        ];
    }

    #[DataProvider('questions')]
    public function test_the_kind_of_question_is_read_in_code(string $question, string $type): void
    {
        $this->assertSame($type, AnswerContext::type($question));
    }

    public function test_a_price_is_kept_as_a_constraint_of_any_kind_of_question(): void
    {
        $this->assertSame(['price'], AnswerContext::constraints('מה מתאים כמתנה לאמא עד 1500?'));
        $this->assertSame(['price'], AnswerContext::constraints('טבעת עם אבן אדומה זולה'));
        $this->assertSame([], AnswerContext::constraints('טבעת עם ספיר כחול'));
    }

    public function test_products_are_kept_down_to_the_drop_never_below_the_first_shown_and_in_the_order_shown(): void
    {
        $selection = $this->select('טבעת זהב עם אבן אדומה באמצע', 'attribute');

        $this->assertSame(['r2', 'r3', 'r5', 'r1', 'r7', 'r4'], array_column($selection['products'], 'external_id'), 'the order shown, not the order scored');
        $reasons = array_column($selection['debug']['products'], 'reason', 'external_id');
        $this->assertSame(['r2' => 'shown_first', 'r3' => 'shown_first', 'r5' => 'shown_first'], array_intersect_key($reasons, array_flip(['r2', 'r3', 'r5'])), 'the safety floor, whatever they scored');
        $this->assertSame(['score', 'score', 'score'], [$reasons['r1'], $reasons['r7'], $reasons['r4']], 'the red-stone rings shown 4th, 7th and 8th');
        $this->assertSame(['below_share_of_best', 'below_share_of_best'], [$reasons['r6'], $reasons['r8']]);

        $r1 = collect($selection['debug']['products'])->firstWhere('external_id', 'r1');
        $this->assertEqualsCanonicalizing(['external_id', 'title', 'position', 'semantic', 'lexical', 'position_score', 'final', 'kept', 'reason'], array_keys($r1), 'every score that chose it is shown');
        $this->assertSame([1.0, 0.75], [$r1['semantic'], $r1['final']]);
    }

    public function test_the_safety_floor_is_a_setting(): void
    {
        Settings::set('assistant.context_min_products', 0);

        $kept = array_column($this->select('טבעת זהב עם אבן אדומה באמצע', 'attribute')['products'], 'external_id');

        $this->assertSame(['r2', 'r1', 'r7', 'r4'], $kept, 'without the floor, the pearls and the bracelet go');
    }

    public function test_a_product_the_question_names_is_always_kept(): void
    {
        $selection = $this->select('יש לכם את SKU-R8 במלאי?', 'stock');

        $this->assertContains('r8', array_column($selection['products'], 'external_id'));
        $this->assertSame('named_in_question', collect($selection['debug']['products'])->firstWhere('external_id', 'r8')['reason']);
    }

    public function test_price_questions_and_recommendations_keep_everything(): void
    {
        foreach (['superlative' => 'איזו טבעת הכי זולה?', 'price_range' => 'טבעת זהב עד 2000', 'price_exact' => 'כמה עולה הטבעת?', 'recommendation' => 'מה מתאים כמתנה?'] as $type => $question) {
            $selection = $this->select($question, $type);
            $this->assertCount(8, $selection['products'], $type);
            $this->assertCount(4, $selection['passages'], $type);
        }
    }

    public function test_a_store_question_needs_the_store_pages_not_the_products(): void
    {
        $selection = $this->select('כמה זמן לוקח משלוח?', 'store');

        $this->assertSame([], $selection['products']);
        $this->assertCount(4, $selection['passages']);

        $without = $this->inShop(fn () => app(AnswerContext::class)->select($this->shop->id, 'כמה זמן לוקח משלוח?', 'store', $this->candidates(), []));
        $this->assertCount(8, $without['products'], 'with no page to answer from, the products stay and the flow is as before');
    }

    public function test_a_product_question_leaves_the_store_text_out_and_keeps_the_pages_near_it(): void
    {
        $selection = $this->select('טבעת זהב עם אבן אדומה באמצע', 'attribute');
        $why = array_column($selection['debug']['passages'], 'reason', 'title');

        $this->assertSame([
            'משלוחים והחזרות' => 'store_text_not_needed_for_product_question',
            'מדריך טבעות' => 'near_the_best_page',
            'ניקוי תכשיטים' => 'far_below_the_best_page',
            'שרשרת זהב' => 'product_not_kept',
        ], $why);
        $this->assertSame(['מדריך טבעות'], array_column($selection['passages'], 'title'));
    }

    public function test_full_context_is_given_exactly_as_before_and_dynamic_gives_less(): void
    {
        $full = $this->ask('באיזו מידה יש את טבעת הגרנט?', AnswerContext::FULL);
        $this->assertCount(8, $full['products']);
        $this->assertSame(['about', 'brand', 'in_stock', 'price_rank', 'ref', 'title'], $this->sortedKeys($full['products'][0]));

        $dynamic = $this->ask('באיזו מידה יש את טבעת הגרנט? ', AnswerContext::DYNAMIC);

        $this->assertLessThan(count($full['products']), count($dynamic['products']));
        $this->assertSame(['about', 'in_stock', 'measures', 'ref', 'sizes', 'title'], $this->sortedKeys($dynamic['products'][0]), 'a size question: the sizes and measures, not the brand');
        $this->assertSame(['6.5', '7'], $dynamic['products'][0]['sizes']);
        $this->assertSame(['weight: 2.4 g'], $dynamic['products'][0]['measures']);

        $context = Run::query()->where('agent', AnswerSiteQuestion::AGENT)->latest('id')->first()->output['context'];
        $this->assertSame(['dynamic', 'size', 8], [$context['mode'], $context['type'], $context['products_shown']]);
        $this->assertLessThan(8, $context['products_sent']);
    }

    public function test_a_size_the_model_was_given_is_not_refused_as_made_up(): void
    {
        Settings::set('assistant.context_mode', AnswerContext::DYNAMIC);
        $this->replies = [
            ['found' => true, 'answer' => 'טבעת הגרנט במידות 6.5 ו-7.', 'refs' => [], 'picks' => [['ref' => 'p4', 'why' => 'הגרנט']]],
            ['supported' => true, 'on_topic' => true, 'picks_fit' => true],
        ];

        $data = $this->askOver('באיזו מידה יש את טבעת הגרנט?');

        $this->assertSame('answered', $data['outcome']);
    }

    public function test_the_dry_evaluation_shows_both_modes_and_why_without_a_model(): void
    {
        $this->evalFile();

        $this->assertSame(0, Artisan::call('assistant:context-eval', ['shop' => $this->shop->id, '--file' => $this->evalPath(), '--dry' => true, '--base-url' => 'https://api.test']));
        $output = Artisan::output();

        $this->assertSame([], $this->calls, 'no model in a dry run');
        $this->assertMatchesRegularExpression('/q1\s*\|.*attribute\s*\|\s*8 → 6\s*\|\s*3 → 1/u', $output);
        $this->assertMatchesRegularExpression('/q1  \+ #4 r1 .*sem 1\.00  lex 0\.67  pos 0\.25  = 0\.75  score/u', $output, 'each product with the scores that kept it');
        $this->assertStringContainsString('far_below_the_best_page', $output, 'each passage with why');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/v1/search/'.SiteKeys::site(self::TOKEN)) && str_contains($r->url(), 'counted=1'), 'searched as the storefront does, without counting it');
    }

    public function test_the_real_evaluation_asks_both_modes_and_saves_nothing_for_shoppers(): void
    {
        $this->evalFile();
        // The ruby ring is 7th of the 8 shown in full context, and 5th of the 6 kept in dynamic.
        $this->replies = [
            ['found' => true, 'answer' => 'טבעת רובי אדומה.', 'refs' => [], 'picks' => [['ref' => 'p7', 'why' => 'אבן אדומה']]],
            ['supported' => true, 'on_topic' => true, 'picks_fit' => true],
            ['found' => true, 'answer' => 'טבעת רובי אדומה.', 'refs' => [], 'picks' => [['ref' => 'p5', 'why' => 'אבן אדומה']]],
            ['supported' => true, 'on_topic' => true, 'picks_fit' => true],
            // q2, a price question: a number that is not the price, in either mode.
            ['found' => true, 'answer' => 'היא עולה בערך 990 בהנחה.', 'refs' => [], 'picks' => [['ref' => 'p8', 'why' => 'גרנט']]],
            ['supported' => true, 'on_topic' => true, 'picks_fit' => true],
            ['found' => true, 'answer' => 'היא עולה בערך 990 בהנחה.', 'refs' => [], 'picks' => [['ref' => 'p8', 'why' => 'גרנט']]],
            ['supported' => true, 'on_topic' => true, 'picks_fit' => true],
        ];

        $this->assertSame(0, Artisan::call('assistant:context-eval', ['shop' => $this->shop->id, '--file' => $this->evalPath(), '--base-url' => 'https://api.test']));
        $output = Artisan::output();

        // q2's writer made up a price; code refused that answer (a number not in what it was given),
        // so the shopper saw no price, and the question passes in both modes.
        $this->assertMatchesRegularExpression('/full\s*\|\s*2\/2/u', $output);
        $this->assertMatchesRegularExpression('/dynamic\s*\|\s*2\/2/u', $output);

        $failures = new \ReflectionMethod(ContextEvalCommand::class, 'failures');
        $this->assertSame(['a price made up: 990'], $failures->invoke(app(ContextEvalCommand::class), 'כמה עולה SKU-R4?',
            ['result' => ['outcome' => 'answered', 'answer' => 'היא עולה 990.', 'picks' => []], 'trace' => []], ['price_must_be' => 499], [], []));
        $this->assertSame(0, $this->inShop(fn () => AssistantAnswer::query()->count()), 'nothing saved for the next shopper');
        $this->assertSame(4, Run::query()->where('agent', AnswerSiteQuestion::AGENT)->get()->filter(fn (Run $r): bool => isset($r->input['evaluation']))->count(), 'two questions, two modes each');

        Artisan::call('assistant:answer-report');
        $this->assertStringContainsString('No questions in this period', Artisan::output(), 'evaluation runs are not shoppers\x27 questions');
    }

    /** @return array{products: list<array<string, mixed>>, passages: list<array<string, mixed>>, debug: array<string, mixed>} */
    private function select(string $question, string $type): array
    {
        return $this->inShop(fn () => app(AnswerContext::class)->select($this->shop->id, $question, $type, $this->candidates(), $this->passages));
    }

    /** @return list<array<string, mixed>> the products as the answerer reads them, in the order shown */
    private function candidates(): array
    {
        $method = new \ReflectionMethod(AnswerSiteQuestion::class, 'candidates');

        return $method->invoke(app(AnswerSiteQuestion::class), self::SHOWN);
    }

    /** @return array<string, mixed> the input the writer was given */
    private function ask(string $question, string $mode): array
    {
        $this->replies = [['found' => false]];
        $this->calls = [];
        $this->inShop(fn () => app(AnswerSiteQuestion::class)->evaluate($this->shop->id, $question, self::SHOWN, $mode));

        return json_decode($this->calls[0]['user'], true);
    }

    /** @return array<string, mixed> */
    private function askOver(string $question): array
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['question' => $question, 'vid' => 'anon-visitor1234567890abcd', 'locale' => 'he', 'products' => self::SHOWN], JSON_UNESCAPED_UNICODE))->json('data');
    }

    /** @param array<string, mixed> $entry @return list<string> */
    private function sortedKeys(array $entry): array
    {
        $keys = array_keys($entry);
        sort($keys);

        return $keys;
    }

    private function evalPath(): string
    {
        return storage_path('framework/testing/eval-'.$this->shop->id.'.json');
    }

    private function evalFile(): void
    {
        @mkdir(dirname($this->evalPath()), 0775, true);
        file_put_contents($this->evalPath(), json_encode(['questions' => [
            ['id' => 'q1', 'question' => 'טבעת זהב עם אבן אדומה באמצע', 'expect' => ['outcome' => 'answered', 'products_any' => ['SKU-R7', 'r1'], 'picks_title_any' => ['אדומ']]],
            ['id' => 'q2', 'question' => 'כמה עולה טבעת הגרנט SKU-R4?', 'expect' => ['outcome' => 'any', 'price_must_be' => 499]],
        ]], JSON_UNESCAPED_UNICODE));
        // As the search endpoint answers: the groups at the top, no data wrapper.
        Http::fake(['api.test/*' => Http::response(['query' => 'x', 'groups' => ['product' => array_map(fn (string $id): array => ['external_id' => $id], self::SHOWN)]])]);
    }
}
