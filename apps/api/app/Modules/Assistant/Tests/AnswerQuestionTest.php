<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerQuestion;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AnswerQuestionTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_ffffffffffffffffffffffffffffffffffffffffffffffff';

    private const VID = 'anon-visitor1234567890abcd';

    private const VERIFIED = ['about_this_product' => true, 'consistent' => true, 'on_topic' => true];

    /** @var object{calls: list<array{model: string, user: string}>, replies: list<array<string, mixed>>} */
    private object $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->product('31538', 'טיק ברומזי 19x120 מ"מ מחורץ', 'מתאים לשימוש חוץ ופנים. מומלץ להשתמש בברגים לעץ קשה.', []);

        $this->model = new class implements ChatModel
        {
            /** @var list<array{model: string, user: string}> */
            public array $calls = [];

            /** @var list<array<string, mixed>> */
            public array $replies = [];

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls[] = ['model' => $model, 'user' => $user];

                return new ModelReply(array_shift($this->replies) ?? [], 500, 100);
            }
        };
        $this->app->instance(ChatModel::class, $this->model);
    }

    public function test_an_answer_checked_once_is_everyones_from_memory_without_a_model(): void
    {
        // By default the first clean, checked answer is the shop's: every shopper after gets it without a model.
        $this->model->replies = [['about_product' => true], ['answer' => 'כן, הוא מתאים לשימוש בחוץ ובפנים.', 'source' => 'store'], self::VERIFIED];
        $this->assertSame('model', $this->ask('האם זה מתאים לחוץ?')->json('data.from'));

        $other = $this->ask('האם זה מתאים לחוץ?', vid: 'anon-visitor2234567890abcd')->json('data');
        $this->assertSame(['answered', 'bank'], [$other['outcome'], $other['from']], 'another shopper: from the shop\'s answers');
        $this->assertCount(3, $this->model->calls, 'no model asked the second time');
        $this->assertNotNull($this->inShop(fn () => AssistantAnswer::query()->sole())->shared_at);
    }

    public function test_a_question_about_the_product_is_answered_once_and_then_from_memory(): void
    {
        // A shop may wait for more different shoppers before an answer is everyone's.
        Settings::set('assistant.share_after_visitors', 3, $this->shop->id);
        $this->model->replies = [['about_product' => true], ['answer' => 'כן, הוא מתאים לשימוש בחוץ ובפנים.', 'source' => 'store'], self::VERIFIED];

        $first = $this->ask('האם זה מתאים לחוץ?')->assertOk()->json('data');
        $this->assertSame(['outcome' => 'answered', 'answer' => 'כן, הוא מתאים לשימוש בחוץ ובפנים.', 'from' => 'model', 'source' => 'store'], $first);
        $this->assertSame(['gpt-5.4-nano', 'gpt-5.4-mini', 'gpt-5.4-nano'], array_column($this->model->calls, 'model'), 'the small model checks the question, the writer answers, the small model checks the answer');
        $this->assertStringContainsString('ברגים לעץ קשה', $this->model->calls[1]['user'], 'the writer gets the product text');
        $this->assertStringContainsString('כן, הוא מתאים', $this->model->calls[2]['user'], 'the checker gets the answer');

        $run = Run::query()->where('agent', 'assistant.answerer')->sole();
        $this->assertGreaterThan(0, (float) $run->cost_usd, 'tokens and cost are on the run');

        $again = $this->ask('  האם זה מתאים לחוץ ')->assertOk()->json('data');
        $this->assertSame('bank', $again['from']);
        $this->assertCount(3, $this->model->calls, 'the same question costs nothing the second time');

        $saved = $this->inShop(fn () => AssistantAnswer::query()->sole());
        $this->assertSame(1, $saved->asked_count, 'the same visitor asking again counts once');
        $this->assertNull($saved->shared_at);

        $box = $this->get('/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/questions?id=31538&locale=he', ['Origin' => 'https://store.test'])->assertOk()->json('data');
        $this->assertSame([], $box['recent'], 'one visitor\'s answer is not shown to others');
        $this->assertNotContains('האם זה מתאים לחוץ?', $box['suggested']);

        // Two more different visitors are each answered afresh and checked; then it is everyone's.
        foreach (['anon-visitor2234567890abcd', 'anon-visitor3234567890abcd'] as $visitor) {
            $this->model->replies = [['about_product' => true], ['answer' => 'כן, הוא מתאים לשימוש בחוץ ובפנים.', 'source' => 'store'], self::VERIFIED];
            $this->assertSame('model', $this->ask('האם זה מתאים לחוץ?', vid: $visitor)->json('data.from'));
        }
        $saved->refresh();
        $this->assertSame([3, 3], [$saved->visitors, $saved->asked_count]);
        $this->assertNotNull($saved->shared_at);
        $this->assertSame('bank', $this->ask('האם זה מתאים לחוץ?', vid: 'anon-visitor4234567890abcd')->json('data.from'));
        $this->assertCount(9, $this->model->calls);

        $box = $this->get('/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/questions?id=31538&locale=he', ['Origin' => 'https://store.test'])->assertOk()->json('data');
        $this->assertSame('האם זה מתאים לחוץ?', $box['suggested'][0], 'what shoppers asked first, then common questions');
        $this->assertCount(4, $box['suggested']);
        $this->assertSame([['question' => 'האם זה מתאים לחוץ?', 'answer' => 'כן, הוא מתאים לשימוש בחוץ ובפנים.', 'source' => 'store']], $box['recent']);
    }

    public function test_what_the_team_wrote_about_the_store_answers_on_a_products_page_too(): void
    {
        // Rocks, 28.10: "all our diamonds are natural" was in the knowledge centre, and "is this a lab diamond?" asked on
        // a ring's page was answered from general knowledge, as if the store had not said.
        $this->model->replies = [['about_product' => true], ['answer' => 'לא מצוין אם הוא טבעי.', 'source' => 'general'], self::VERIFIED];
        $this->ask('האם זה יהלום מעבדה?');
        $this->assertStringNotContainsString('"store"', $this->model->calls[1]['user']);

        $this->travel(1)->minutes();
        $this->inShop(fn () => app(BuildShopKnowledge::class)->addOwn('כל היהלומים באתר שלנו טבעיים לחלוטין.'));
        $this->model->replies = [['about_product' => true], ['answer' => 'כל היהלומים שלנו טבעיים.', 'source' => 'store'], self::VERIFIED];

        $data = $this->ask('האם זה יהלום מעבדה?')->json('data');
        $this->assertSame(['model', 'store', 'כל היהלומים שלנו טבעיים.'], [$data['from'], $data['source'], $data['answer']], 'written again: the team changed its lines since');
        $writer = (array) json_decode($this->model->calls[4]['user'], true);
        $checker = (array) json_decode($this->model->calls[5]['user'], true);
        $this->assertStringContainsString('טבעיים לחלוטין', (string) ($writer['store'] ?? ''), "the writer reads the team's lines");
        $this->assertStringContainsString('טבעיים לחלוטין', (string) ($checker['store'] ?? ''), "and the checker, so the store's word is no unsupported claim");
    }

    public function test_the_writer_reads_the_products_card_without_its_prices_or_stock(): void
    {
        $this->inShop(fn () => CatalogProduct::query()->where('external_id', '31538')->update(['payload' => [
            'title' => 'טיק ברומזי 19x120 מ"מ מחורץ', 'description' => 'מתאים לשימוש חוץ ופנים.', 'stock' => ['quantity' => 7, 'managed' => true],
            'variations' => [['external_id' => '1', 'title' => 'אורך 2.4 מטר', 'price' => ['price' => 79], 'stock' => ['in_stock' => true, 'quantity' => 4]]],
        ]]));
        $this->model->replies = [['about_product' => true], ['answer' => 'יש באורך 2.4 מטר.', 'source' => 'store'], self::VERIFIED];

        $this->ask('באילו אורכים זה מגיע?')->assertOk();

        $sent = json_decode($this->model->calls[1]['user'], true);
        $this->assertSame(['אורך 2.4 מטר'], $sent['product']['options'], 'its options by name, so "which lengths" has an answer');
        $this->assertSame('טיק ברומזי 19x120 מ"מ מחורץ', $sent['product']['title']);
        foreach (['price', 'regular_price', 'stock', 'in_stock', 'variants'] as $never) {
            $this->assertArrayNotHasKey($never, $sent['product'], 'prices and stock are the store\'s page to say, live');
        }
    }

    public function test_general_knowledge_answers_only_after_code_and_a_second_model_check_them(): void
    {
        // The store's text says nothing about using it: general guidance for products like it, checked.
        $this->model->replies = [['about_product' => true], ['answer' => 'מחברים צינור מים ואז את החשמל, ומתחילים במרחק מהמשטח. כדאי לקרוא את הוראות היצרן.', 'source' => 'general'], self::VERIFIED];
        $data = $this->ask('איך מפעילים את זה?')->json('data');
        $this->assertSame(['answered', 'general'], [$data['outcome'], $data['source']]);

        // The checker finds it inconsistent with the product: not shown.
        $this->model->replies = [['about_product' => true], ['answer' => 'מתאים גם לשימוש מתחת למים.', 'source' => 'general'], ['about_this_product' => true, 'consistent' => false, 'on_topic' => true]];
        $this->assertSame('no_info', $this->ask('אפשר להשתמש בזה מתחת למים?')->json('data.outcome'));
        $refused = $this->inShop(fn () => AssistantAnswer::query()->where('question_key', Question::key('אפשר להשתמש בזה מתחת למים?'))->sole());
        $this->assertNull($refused->answer, 'a refused answer is not kept');
        $this->assertSame('not_consistent', Run::query()->findOrFail($refused->run_id)->output['refused']);

        // A number the store's information does not have: refused in code, no checker call.
        $calls = count($this->model->calls);
        $this->model->replies = [['about_product' => true], ['answer' => 'הלחץ המרבי הוא 150 בר.', 'source' => 'general']];
        $this->assertSame('no_info', $this->ask('מה הלחץ המרבי?')->json('data.outcome'));
        $this->assertCount($calls + 2, $this->model->calls);

        // A question answered by an older prompt is asked again.
        $this->inShop(fn () => AssistantAnswer::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $this->inShop(fn () => CatalogProduct::query()->where('external_id', '31538')->value('id')),
            'question_key' => Question::key('איך שומרים עליו?'), 'question' => 'איך שומרים עליו?',
            'outcome' => AssistantAnswer::NO_INFO, 'prompt_version' => 1, 'last_asked_at' => now(),
        ]));
        $this->model->replies = [['about_product' => true], ['answer' => 'מנקים אותו אחרי כל שימוש ושומרים במקום יבש.', 'source' => 'general'], self::VERIFIED];
        $this->assertSame('answered', $this->ask('איך שומרים עליו?')->json('data.outcome'));
    }

    public function test_code_tells_a_question_about_the_store_from_one_about_the_product(): void
    {
        foreach (['תוך כמה זמן זה מגיע?', 'אפשר להחזיר או להחליף?', 'מתי זה יגיע?', 'כמה עולה משלוח?', 'אפשר להגדיל את הטבעת?', 'יש אחריות?', 'אפשר לשלם בתשלומים?'] as $store) {
            $this->assertTrue(AnswerQuestion::aboutTheStore($store), $store);
        }
        // "comes in lengths", cleaning it, its smaller version: the product's own
        foreach (['באילו אורכים זה מגיע?', 'איזה חומר ניקוי מתאים?', 'יש את זה בגרסה הקטנה?', 'האם זה מתאים לחוץ?', 'זה מגיע עם קופסה?'] as $product) {
            $this->assertFalse(AnswerQuestion::aboutTheStore($product), $product);
        }
    }

    public function test_a_question_about_something_else_never_reaches_the_writer(): void
    {
        $this->model->replies = [['about_product' => false]];

        $data = $this->ask('תכתוב לי שיר על ים')->assertOk()->json('data');

        $this->assertSame('out_of_scope', $data['outcome']);
        $this->assertSame(__('assistant::answers.out_of_scope', [], 'he'), $data['answer'], 'the shopper is pointed at the team, not stonewalled');
        $this->assertCount(1, $this->model->calls);

        $this->ask('תכתוב לי שיר על ים!')->assertOk();
        $this->assertCount(1, $this->model->calls, 'asked again: refused from memory');
    }

    public function test_code_refuses_contact_details_prices_the_daily_limit_and_the_spending_cap(): void
    {
        $this->assertSame('personal_data', $this->ask('תתקשרו אליי 052-1234567 לגבי המוצר')->json('data.outcome'));
        $this->assertCount(0, $this->model->calls, 'contact details never reach a model');

        $this->model->replies = [['about_product' => true], ['answer' => 'המחיר הוא 89 ₪ למטר.', 'source' => 'store']];
        $this->assertSame('no_info', $this->ask('כמה עולה מטר?')->json('data.outcome'), 'a price in an answer goes stale');

        Settings::set('assistant.questions_per_visitor_per_day', 1, $this->shop->id);
        $this->assertSame('limit', $this->ask('איך מתקינים את זה?')->json('data.outcome'));

        Settings::set('assistant.questions_per_visitor_per_day', 50, $this->shop->id);
        Settings::set('ai.monthly_spend_cap_usd', 0);
        $calls = count($this->model->calls);
        $this->assertSame('unavailable', $this->ask('איך שומרים עליו?')->json('data.outcome'));
        $this->assertCount($calls, $this->model->calls, 'over the cap nothing is sent');

        $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/ask', server: ['HTTP_ORIGIN' => 'https://evil.test'], content: '{}')->assertStatus(403);
    }

    public function test_a_reader_can_ask_a_guide_to_sum_itself_up(): void
    {
        $guide = $this->article('900', 'איך בוחרים מקדחה', 'הספק חשוב פחות ממה שנדמה. לעבודה בבטון צריך פטישון, לעץ מספיקה מקדחה רגילה.');
        $this->model->replies = [['about_product' => true], ['answer' => 'בקצרה: לבטון פטישון, לעץ מקדחה רגילה, וההספק פחות קריטי.', 'source' => 'store'], self::VERIFIED];

        $answer = $this->ask('תסכם לי את המאמר בכמה מילים', 'content', '900')->assertOk()->json('data');

        $this->assertSame('answered', $answer['outcome']);
        $this->assertSame('בקצרה: לבטון פטישון, לעץ מקדחה רגילה, וההספק פחות קריטי.', $answer['answer']);
        $this->assertStringContainsString('פטישון', $this->model->calls[1]['user'], 'the writer gets the guide itself');
        $this->assertStringContainsString('"article"', $this->model->calls[1]['user'], 'and is told it is a guide, not a product');

        // Saved against the guide, so the next reader gets it without a model.
        $saved = app(TenantContext::class)->run($this->shop->id, fn () => AssistantAnswer::query()->firstWhere('content_id', $guide->id));
        $this->assertNotNull($saved);
        $this->assertNull($saved->product_id);

        $this->model->calls = [];
        $again = $this->ask('תסכם לי את המאמר בכמה מילים', 'content', '900')->assertOk()->json('data');
        $this->assertSame('bank', $again['from']);
        $this->assertSame([], $this->model->calls, 'no model the second time');

        // What the question box offers a reader before they type.
        $suggested = $this->getJson('/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/questions?id=900&type=content')->json('data.suggested');
        $this->assertContains('תסכם לי את המאמר בכמה מילים', $suggested);
        $this->assertNotContains('מה עוד צריך לקנות יחד איתו?', $suggested, 'a guide is not a product');
    }

    private function ask(string $question, string $type = 'product', string $id = '31538', string $vid = self::VID): TestResponse
    {
        return $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['id' => $id, 'type' => $type, 'question' => $question, 'vid' => $vid, 'locale' => 'he'], JSON_UNESCAPED_UNICODE));
    }
}
