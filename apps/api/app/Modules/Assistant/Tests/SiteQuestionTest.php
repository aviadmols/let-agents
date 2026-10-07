<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
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

    /** @var list<array{provider: AiProviderName, model: string, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    /** @var list<array<string, mixed>> what the fake passage search answers */
    public array $passages = [];

    protected function setUp(): void
    {
        parent::setUp();

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
                $this->test->calls[] = ['provider' => $provider, 'model' => $model, 'user' => $user];

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
            ['supported' => true, 'on_topic' => true],
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

    public function test_what_the_pages_do_not_say_is_never_shown(): void
    {
        // A number the cited pages do not have: refused in code, the checker is not asked.
        $this->replies = [['found' => true, 'answer' => 'צריך 45 ברגים למטר.', 'refs' => ['s1']]];
        $this->assertSame('no_info', $this->ask('כמה ברגים צריך?')->json('data.outcome'));
        $this->assertCount(1, $this->calls);

        // The checker finds something the pages do not say.
        $this->replies = [
            ['found' => true, 'answer' => 'שניים בכל מפגש, ועדיף נירוסטה ליד הים.', 'refs' => ['s1']],
            ['supported' => false, 'on_topic' => true],
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
        $this->replies = [['found' => true, 'answer' => 'כן, יש משלוח לכל הארץ, כולל אילת.', 'refs' => ['s1']], ['supported' => true, 'on_topic' => true]];

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
}
