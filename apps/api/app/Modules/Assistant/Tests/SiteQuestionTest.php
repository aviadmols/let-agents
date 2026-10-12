<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Contracts\ProductsNamed;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A question typed in the search box: answered from the store's own pages, written by one family,
 * checked by the other, shown with the pages it came from, and kept for the next shopper.
 */
final class SiteQuestionTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'lat_cccccccccccccccccccccccccccccccccccccccccccccccc';

    private const VID = 'anon-visitor1234567890abcd';

    /** @var list<array{provider: AiProviderName, model: string, user: string, max: int}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    /** @var list<array<string, mixed>> what the fake passage search answers */
    public array $passages = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The topic check is its own model call, tested in GuardTest; these test what follows it.
        Features::override('assistant.site_scope', false);

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $product = $this->product('31538', 'בורג נירוסטה לדק 5X50', 'בורג לעץ קשה.', []);
        $guide = $this->article('600', 'כמה ברגים צריך למטר דק', 'למטר מרובע של דק צריך כ-30 ברגים, שניים בכל מפגש של קרש עם קורה.');
        $this->inShop(fn () => $guide->forceFill(['url' => 'https://store.test/?p=600'])->save());
        $this->passages = [
            ['source' => 'content', 'source_id' => $guide->id, 'external_id' => '600', 'title' => 'כמה ברגים צריך למטר דק', 'text' => 'למטר מרובע של דק צריך כ-30 ברגים, שניים בכל מפגש של קרש עם קורה.', 'similarity' => 0.71],
            ['source' => 'product', 'source_id' => $product->id, 'external_id' => '31538', 'title' => 'בורג נירוסטה לדק 5X50', 'text' => 'בורג לעץ קשה.', 'similarity' => 0.52],
            ['source' => 'product', 'source_id' => $product->id, 'external_id' => '31538', 'title' => 'בורג נירוסטה לדק 5X50', 'text' => 'משהו רחוק.', 'similarity' => 0.1],
        ];

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly SiteQuestionTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'model' => $model, 'user' => $user, 'max' => $maxOutputTokens];

                return new ModelReply(array_shift($this->test->replies) ?? [], 700, 120);
            }
        });
        $this->app->instance(Passages::class, new class($test) implements Passages
        {
            public function __construct(private readonly SiteQuestionTest $test) {}

            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                return array_slice($this->test->passages, 0, $limit);
            }
        });
    }

    public function test_one_family_writes_from_the_pages_the_other_checks_and_the_answer_is_kept(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר מרובע, שניים בכל מפגש של קרש עם קורה.', 'refs' => ['s1', 's9']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];

        $data = $this->ask('כמה ברגים צריך למטר דק?')->assertOk()->json('data');

        $this->assertSame('answered', $data['outcome']);
        $this->assertSame('model', $data['from']);
        $this->assertSame([['title' => 'כמה ברגים צריך למטר דק', 'url' => 'https://store.test/?p=600', 'type' => 'content']], $data['sources'], 'only the page it cited, as the shopper can open it');
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'));
        $this->assertStringNotContainsString('משהו רחוק', $this->calls[0]['user'], 'a piece below the nearness floor is never sent');
        $this->assertStringContainsString('30 ברגים', $this->calls[1]['user'], 'the checker gets the cited text');
        $this->assertGreaterThan(0, (float) Run::query()->where('agent', AnswerSiteQuestion::AGENT)->sole()->cost_usd);

        $again = $this->ask('כמה ברגים צריך למטר דק')->json('data');
        $this->assertSame(['bank', $data['sources']], [$again['from'], $again['sources']]);
        $this->assertCount(2, $this->calls, 'the same question costs nothing the second time');

        $saved = $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->sole());
        $this->assertNull($saved->product_id);
        $this->assertNull($saved->content_id);
    }

    public function test_the_checker_is_haiku_5_5_with_room_to_think_and_what_it_did_is_kept(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'בערך 30 ברגים למטר מרובע.', 'refs' => ['s1']],
            ['supported' => true, 'on_topic' => true, 'safe' => true],
        ];
        $this->ask('כמה ברגים צריך למטר דק?');

        $this->assertSame(['claude-haiku-5-5', 1500], [$this->calls[1]['model'], $this->calls[1]['max']], 'its thinking counts as output: 400 tokens would cut it short');
        $check = Run::query()->where('agent', AnswerSiteQuestion::AGENT)->sole()->output['check'];
        $this->assertEquals(['ran' => true, 'type' => 'store_info', 'verdict' => 'kept', 'model' => 'claude-haiku-5-5', 'in' => 700, 'out' => 120],
            array_intersect_key($check, array_flip(['ran', 'type', 'verdict', 'model', 'in', 'out'])));
        $this->assertSame(0.00013, $check['cost_usd'], '700 in at $0.10 and 120 out at $0.50 a million');
        $this->assertIsInt($check['ms']);

        // The checker refuses what the page does not say.
        $this->replies = [
            ['found' => true, 'answer' => 'שניים בכל מפגש, ועדיף נירוסטה ליד הים.', 'refs' => ['s1']],
            ['supported' => false, 'on_topic' => true, 'safe' => true],
        ];
        $this->ask('איך מבריגים דק?');

        // Code refuses first, and the checker is not asked.
        $this->replies = [['found' => true, 'answer' => 'צריך 45 ברגים למטר.', 'refs' => ['s1']]];
        $this->ask('כמה ברגים צריך?');

        $checks = Run::query()->where('agent', AnswerSiteQuestion::AGENT)->orderBy('id')->get()->map(fn (Run $r): array => $r->output['check'])->all();
        $this->assertSame('rejected', $checks[1]['verdict']);
        $this->assertSame(['ran' => false, 'type' => 'store_info', 'skipped' => 'number_not_in_the_pages'], $checks[2]);

        $this->assertSame(0, Artisan::call('assistant:checker-report'));
        $report = Artisan::output();
        $this->assertMatchesRegularExpression('/store_info · claude-haiku-5-5\s*\|\s*2\s*\|\s*2\s*\|\s*0\s*\|\s*1\s*\|\s*0\s*\|\s*0\s*\|\s*1\s*\|\s*50%/u', $report,
            'two checked, one kept and one refused: half changed');
        $this->assertMatchesRegularExpression('/store_info · —\s*\|\s*1\s*\|\s*0\s*\|\s*1\s*\|/u', $report, 'the one code refused, never checked');
    }

    public function test_what_the_pages_do_not_say_is_never_shown(): void
    {
        // A number the cited pages do not have: refused in code, the checker is not asked.
        $this->replies = [['found' => true, 'answer' => 'צריך 45 ברגים למטר.', 'refs' => ['s1']]];
        $this->assertSame('no_info', $this->ask('כמה ברגים צריך?')->json('data.outcome'));
        $this->assertCount(1, $this->calls);

        // The checker finds something the pages do not say.
        $this->replies = [
            ['found' => true, 'answer' => 'שניים בכל מפגש, ועדיף נירוסטה ליד הים.', 'refs' => ['s1']],
            ['supported' => false, 'on_topic' => true, 'safe' => true],
        ];
        $data = $this->ask('איך מבריגים דק?')->json('data');
        $this->assertSame(['no_info', __('assistant::answers.site.no_info', [], 'he')], [$data['outcome'], $data['answer']]);

        // The writer cites nothing it was given.
        $this->replies = [['found' => true, 'answer' => 'כן.', 'refs' => ['s7']]];
        $this->assertSame('no_info', $this->ask('יש לכם ברגים?')->json('data.outcome'));
    }

    public function test_nothing_near_the_question_asks_no_writer_and_is_asked_again_later(): void
    {
        $this->passages = [];

        $this->assertSame('no_info', $this->ask('יש משלוח לאילת?')->json('data.outcome'));
        $this->assertSame([], $this->calls);

        $this->passages = [['source' => 'content', 'source_id' => $this->inShop(fn () => CatalogContent::query()->value('id')), 'external_id' => '600', 'title' => 'משלוחים', 'text' => 'יש משלוח לכל הארץ, כולל אילת.', 'similarity' => 0.8]];
        $this->replies = [['found' => true, 'answer' => 'כן, יש משלוח לכל הארץ, כולל אילת.', 'refs' => ['s1']], ['supported' => true, 'on_topic' => true, 'safe' => true]];

        $this->assertSame('no_info', $this->ask('יש משלוח לאילת?')->json('data.outcome'), 'the same day: from memory');
        $this->travel((int) Settings::get('assistant.site_retry_days') + 1)->days();
        $this->assertSame('answered', $this->ask('יש משלוח לאילת?')->json('data.outcome'), 'later: asked again, the store may have added the page');
    }

    public function test_a_writer_never_checks_its_own_family_and_a_page_answer_is_not_a_site_answer(): void
    {
        $productId = $this->inShop(fn () => CatalogProduct::query()->value('id'));
        $this->inShop(fn () => AssistantAnswer::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $productId, 'question_key' => Question::key('כמה ברגים צריך?'),
            'question' => 'כמה ברגים צריך?', 'answer' => 'על המוצר הזה.', 'outcome' => AssistantAnswer::ANSWERED, 'prompt_version' => 3, 'last_asked_at' => now(),
        ]));
        Settings::set('assistant.site_check_provider', 'openai');

        $this->assertSame('unavailable', $this->ask('כמה ברגים צריך?')->json('data.outcome'), 'the page answer is not served to the whole site');
        $this->assertSame([], $this->calls, 'stopped before any model was asked');
    }

    public function test_only_the_shop_own_site_may_ask(): void
    {
        $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://other.test', 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['question' => 'שאלה', 'vid' => self::VID]))->assertForbidden();
        $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain'],
            content: json_encode(['question' => 'שאלה', 'vid' => 'nobody']))->assertStatus(422);
    }

    private function ask(string $question): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => self::VID, 'locale' => 'he'], JSON_UNESCAPED_UNICODE));
    }

    public function test_the_cheapest_is_arithmetic_code_answers_it_and_no_model_is_asked(): void
    {
        $this->drills();

        $data = $this->askWith('איזו מברגה הכי זולה?', ['701', '704', '702', '703'])->json('data');

        $this->assertSame(['answered', 'code'], [$data['outcome'], $data['from']]);
        $this->assertSame(['702', '701'], array_column($data['picks'], 'external_id'), 'cheapest in stock first; the sold-out one and the bit are not drills on offer');
        $this->assertStringContainsString('מברגה איינהל', $data['answer']);
        $this->assertSame([], $this->calls, 'no model for arithmetic');
    }

    public function test_a_price_range_is_arithmetic_too_and_only_what_the_question_names_is_picked(): void
    {
        $this->product('801', 'עגילים צמודים כדורי זהב חלולים', 'עגילים.', [], ['price' => '450.00', 'in_stock' => true]);
        $this->product('802', 'עגילי חישוק זהב 14K', 'עגילים.', [], ['price' => '480.00', 'in_stock' => true]);
        $this->product('803', 'תליון מפתח עדין', 'תליון.', [], ['price' => '350.00', 'in_stock' => true]);
        $this->product('804', 'עגילי יהלום', 'עגילים.', [], ['price' => '900.00', 'in_stock' => true]);
        $this->product('805', 'חישוקי זהב 14K', 'חישוקים.', [], ['price' => '300.00', 'in_stock' => true]);
        // The search reads the words (see SearchFlowTest): the hoops are in the earrings category.
        $this->app->instance(ProductsNamed::class, new class implements ProductsNamed
        {
            public function among(string $shopId, string $question, array $externalIds): array
            {
                return str_contains($question, 'עגילים') ? array_values(array_intersect($externalIds, ['801', '802', '804', '805'])) : [];
            }
        });

        $data = $this->askWith('עגילים עד 500 ש"ח?', ['803', '802', '801', '804', '805'])->json('data');

        $this->assertSame(['answered', 'code'], [$data['outcome'], $data['from']]);
        $this->assertSame(['805', '801', '802'], array_column($data['picks'], 'external_id'), 'earrings up to 500 as the search reads them, cheapest first: the hoops whose title never says earrings, not the pendant, not the 900');
        $this->assertStringContainsString('חישוקי זהב', $data['answer']);
        $this->assertSame([], $this->calls, 'no model for arithmetic');

        // Nothing it names in the range: a model reads it, as before.
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => []]];
        $this->assertSame('model', $this->askWith('צמיד עד 500', ['803', '801'])->json('data.from'));
    }

    public function test_the_cheapest_with_no_product_named_is_the_whole_stores_merchandise(): void
    {
        // What the words happened to find: a ring and a pendant, far from the cheapest.
        $typed = fn (string $type): array => ['payload' => ['title' => '', 'description' => '', 'categories' => [], 'attributes' => [], 'meta' => [], 'product_type' => $type]];
        $this->product('901', 'טבעת יהלום', 'טבעת.', [], ['price' => '4900.00'] + $typed('טבעות'));
        $this->product('902', 'תליון לב משובץ', 'תליון.', [], ['price' => '2200.00'] + $typed('תליונים'));
        $this->product('903', 'תליון מפתח עדין', 'תליון.', [], ['price' => '350.00'] + $typed('תליונים'));
        $this->product('904', 'עגילים צמודים כדורי זהב', 'עגילים.', [], ['price' => '450.00'] + $typed('עגילים'));
        $this->product('905', 'עגילים שאזלו', 'עגילים.', [], ['price' => '100.00', 'in_stock' => false] + $typed('עגילים'));
        // Not merchandise: a ring sizer with no kind, a gift card.
        $this->product('906', 'מדיד טבעות', 'מדיד.', [], ['price' => '5.00']);
        $this->product('907', 'כרטיס מתנה - Gift Card', 'שובר.', [], ['price' => '100.00'] + $typed('כרטיס מתנה'));

        foreach (['מה הכי זול בין המוצרים?', 'מה הכי זול באתר', 'יש לכם משהו זול?'] as $question) {
            $data = $this->askWith($question, ['901', '902'])->json('data');

            $this->assertSame(['answered', 'code'], [$data['outcome'], $data['from']], $question);
            $this->assertSame(['903', '904', '902'], array_column($data['picks'], 'external_id'), $question.': the store\'s cheapest in stock');
            $this->assertStringContainsString('באתר', $data['answer']);
        }

        $this->assertSame(['901', '902', '904'], array_column($this->askWith('מה הכי יקר שיש לכם?', [])->json('data.picks'), 'external_id'), 'and the dearest, even when the words found nothing');
        $this->assertSame([], $this->calls, 'no model for arithmetic');

        // A product named still means that product, of what was found.
        $this->assertSame(['902'], array_column($this->askWith('איזה תליון הכי זול?', ['901', '902'])->json('data.picks'), 'external_id'));
    }

    public function test_a_question_that_names_nothing_is_about_what_the_conversation_just_showed(): void
    {
        $typed = fn (string $type): array => ['payload' => ['title' => '', 'description' => '', 'categories' => [], 'attributes' => [], 'meta' => [], 'product_type' => $type]];
        $this->product('901', 'טבעת יהלום', 'טבעת.', [], ['price' => '4900.00'] + $typed('טבעות'));
        $this->product('902', 'תליון לב משובץ', 'תליון.', [], ['price' => '2200.00'] + $typed('תליונים'));
        $this->product('903', 'תליון מפתח עדין', 'תליון.', [], ['price' => '350.00'] + $typed('תליונים'));
        $this->product('904', 'עגילים צמודים כדורי זהב', 'עגילים.', [], ['price' => '450.00'] + $typed('עגילים'));
        // The drawer's turn before showed these three; the words of the question found the ring alone.
        $before = ['901', '902', '904'];

        foreach (['מה הכי זול?', 'הכי זול', 'איזה מהם הכי זול?'] as $question) {
            $data = $this->askWith($question, ['901'], $before)->json('data');

            $this->assertSame(['answered', 'code', 'previous'], [$data['outcome'], $data['from'], $data['context'] ?? null], $question);
            $this->assertSame(['904', '902', '901'], array_column($data['picks'], 'external_id'), $question.': the cheapest of what was shown');
            $this->assertStringNotContainsString('באתר', $data['answer']);
        }

        $this->assertSame(['901', '902', '904'], array_column($this->askWith('ומה הכי יקר?', [], $before)->json('data.picks'), 'external_id'));

        // The whole store, when the shopper says so; and with no turn before, as always.
        $store = $this->askWith('מה הכי זול באתר?', [], $before)->json('data');
        $this->assertSame(['903', '904', '902'], array_column($store['picks'], 'external_id'));
        $this->assertArrayNotHasKey('context', $store);
        $this->assertSame(['903', '904', '902'], array_column($this->askWith('מה הכי זול?', [])->json('data.picks'), 'external_id'));

        // A product named is that product, not the turn before.
        $this->assertSame(['903', '902'], array_column($this->askWith('איזה תליון הכי זול?', ['902', '903'], $before)->json('data.picks'), 'external_id'));
        $this->assertSame([], $this->calls, 'no model for arithmetic');
    }

    public function test_a_follow_up_the_model_answers_is_about_those_products_and_is_never_kept(): void
    {
        $this->drills();
        $this->replies = [
            ['found' => true, 'answer' => 'לבית, מברגה איינהל.', 'refs' => [], 'picks' => [['ref' => 'p2', 'why' => 'מתאימה לבית']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $data = $this->askWith('איזה מהם מתאים לבית?', [], ['701', '702'])->json('data');

        $sent = collect($this->calls)->map(fn (array $call): ?array => json_decode($call['user'], true))->first(fn (?array $input): bool => isset($input['products']));
        $this->assertNotNull($sent, 'the writer was asked');
        $this->assertCount(2, $sent['products'], 'the products of the turn before, and only them');
        $this->assertSame('previous', $data['context'] ?? null);
        $this->assertSame(0, $this->inShop(fn () => AssistantAnswer::query()->count()), 'an answer about one conversation is not the next shopper\'s');
    }

    public function test_code_reads_the_results_before_the_model_ranks_never_prices(): void
    {
        $this->drills();
        $this->replies = [
            ['found' => true, 'answer' => 'למברגה ביתית, מברגה איינהל.', 'refs' => [], 'picks' => [['ref' => 'p3', 'why' => 'מתאימה לבית']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];

        $this->askWith('איזו מברגה מתאימה לבית?', ['701', '704', '702', '703'])->assertOk();

        $input = json_decode($this->calls[0]['user'], true);
        $this->assertNull($input['analysis']['asks']);
        $this->assertSame(['p2', 'p3', 'p1'], $input['analysis']['in_stock_cheapest_first']);
        $this->assertSame(['p4'], $input['analysis']['out_of_stock']);
        $this->assertSame(1, $input['products'][1]['price_rank']);
        $this->assertStringNotContainsString('240', $this->calls[0]['user'], 'ranks, never prices, reach the model');
        $this->assertStringContainsString('"price_rank":2', $this->calls[1]['user'], 'the checker sees the measure the why rests on');
    }

    private function drills(): void
    {
        $this->product('701', 'מברגה מקיטה 18V', 'מברגה נטענת.', [], ['price' => '890.00', 'brand' => 'Makita', 'in_stock' => true]);
        $this->product('702', 'מקדחה/מברגה איינהל', 'מברגה רוטטת.', [], ['price' => '240.00', 'brand' => 'Einhell', 'in_stock' => true, 'on_sale' => true]);
        $this->product('703', 'מברגה זולה שאזלה', 'מברגה.', [], ['price' => '99.00', 'in_stock' => false]);
        $this->product('704', 'ביט למברגה 25 מ"מ', 'ביט.', [], ['price' => '7.00', 'in_stock' => true]);
    }

    /**
     * @param  list<string>  $products
     * @param  list<string>  $previous  what the drawer's turn before showed
     */
    private function askWith(string $question, array $products, array $previous = []): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => self::VID, 'locale' => 'he', 'products' => $products] + ($previous === [] ? [] : ['previous' => $previous]), JSON_UNESCAPED_UNICODE));
    }
}
