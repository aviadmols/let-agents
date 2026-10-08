<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\ReviewSearchAsks;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A question in the search box with the products the search showed: the assistant picks only
 * from them, a second family checks the picks, no exact match offers the shop's WhatsApp, every
 * question is logged with what the shopper did next, and every morning the day is scored.
 */
final class SearchAsksTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'lat_dddddddddddddddddddddddddddddddddddddddddddddddd';

    private const VID = 'anon-visitor1234567890abcd';

    /** @var list<array{provider: AiProviderName, user: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->product('701', 'יריעה ביטומנית לאיטום גגות 4 מ"מ', 'יריעה לאיטום גג בטון שטוח.', []);
        $this->product('702', 'פריימר ביטומני ליסוד', 'יסוד לפני איטום ביטומני.', []);
        $this->product('703', 'מברשת רולר', 'רולר לצביעה.', []);

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly SearchAsksTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'user' => $user];

                return new ModelReply(array_shift($this->test->replies) ?? [], 600, 150);
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

    public function test_the_assistant_picks_only_from_what_was_shown_and_the_check_can_drop_the_picks(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'מתחילים בפריימר ואז יריעה ביטומנית.', 'refs' => [], 'picks' => [
                ['ref' => 'p2', 'why' => 'יסוד שהיריעה נצמדת אליו.'],
                ['ref' => 'p1', 'why' => 'יריעה לגג בטון שטוח.'],
                ['ref' => 'p9', 'why' => 'לא הוצג.'],
                ['ref' => 'p2', 'why' => 'שוב.'],
            ]],
            ['supported' => true, 'on_topic' => true, 'picks_fit' => true],
        ];

        $data = $this->ask('מה כדאי לאיטום גג', ['701', '702', '703'])->assertOk()->json('data');

        $this->assertSame('answered', $data['outcome']);
        $this->assertSame(['702', '701'], array_column($data['picks'], 'external_id'), 'only products it was shown, once each, in its order');
        $this->assertSame('יסוד שהיריעה נצמדת אליו.', $data['picks'][0]['why']);
        $this->assertFalse($data['whatsapp']);
        $this->assertStringContainsString('יריעה ביטומנית לאיטום גגות', $this->calls[0]['user'], 'the writer gets the products shown');
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column($this->calls, 'provider'));

        $ask = $this->inShop(fn () => AssistantSearchAsk::query()->findOrFail($data['ask_id']));
        $this->assertSame(['701', '702', '703'], $ask->products);
        $this->assertSame(['702', '701'], array_column($ask->picks, 'external_id'));

        // The same question again: from memory, the same picks, logged again.
        $again = $this->ask('מה כדאי לאיטום גג', ['701', '702'])->json('data');
        $this->assertSame(['bank', ['702', '701']], [$again['from'], array_column($again['picks'], 'external_id')]);
        $this->assertCount(2, $this->calls);
        $this->assertSame(2, $this->inShop(fn () => AssistantSearchAsk::query()->count()));
    }

    public function test_no_exact_match_offers_whatsapp_and_what_the_shopper_does_is_logged(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => '', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'רולר.']]],
            ['supported' => true, 'on_topic' => true, 'picks_fit' => false],
        ];

        $data = $this->ask('יש לכם גנרטור 5 קילוואט?', ['703'])->json('data');

        $this->assertSame('no_match', $data['outcome'], 'a pick the second model finds unfitting is no match');
        $this->assertTrue($data['whatsapp']);
        $this->assertSame(__('assistant::answers.site.no_match', [], 'he'), $data['answer']);

        $this->events([['type' => 'ask_whatsapp', 'q' => 'גנרטור', 'ask_id' => $data['ask_id']]]);
        $ask = $this->inShop(fn () => AssistantSearchAsk::query()->findOrFail($data['ask_id']));
        $this->assertTrue($ask->whatsapp_shown);
        $this->assertTrue($ask->whatsapp_clicked);

        // A pick the shopper opens is recorded; a product the assistant never picked is not.
        $this->replies = [
            ['found' => true, 'answer' => 'יריעה ביטומנית.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'לגג שטוח.']]],
            ['supported' => true, 'on_topic' => true, 'picks_fit' => true],
        ];
        $picked = $this->ask('במה אוטמים גג שטוח', ['701', '703'])->json('data');
        $this->events([
            ['type' => 'ask_pick', 'q' => 'גג', 'ask_id' => $picked['ask_id'], 'id' => '701'],
            ['type' => 'ask_pick', 'q' => 'גג', 'ask_id' => $picked['ask_id'], 'id' => '703'],
        ]);
        $this->assertSame(['701'], $this->inShop(fn () => AssistantSearchAsk::query()->findOrFail($picked['ask_id'])->picked));

        // The shop can turn the WhatsApp offer off.
        Features::override('assistant.search_whatsapp', false, $this->shop->id);
        $this->replies = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => []]];
        $this->assertFalse($this->ask('יש לכם מעלית לבית?', ['703'])->json('data.whatsapp'));
    }

    public function test_every_morning_the_day_is_scored_by_another_family_and_shown_to_the_shop(): void
    {
        $this->assertSame('succeeded', app(ReviewSearchAsks::class)->handle($this->shop->id)->status->value);
        $this->assertSame([], $this->calls, 'a quiet day asks no model');
        $this->assertSame(AssistantAskReview::QUIET, $this->inShop(fn () => AssistantAskReview::query()->sole()->status));

        $this->travel(1)->days();
        $yesterday = now()->subDay();
        $this->inShop(function () use ($yesterday): void {
            foreach ([['במה אוטמים גג', 'answered', true], ['גנרטור', 'no_match', false]] as [$q, $outcome, $picked]) {
                AssistantSearchAsk::query()->create([
                    'shop_id' => $this->shop->id, 'day' => $yesterday->toDateString(), 'question' => $q, 'products' => ['701'],
                    'outcome' => $outcome, 'from' => 'model', 'picks' => $picked ? [['external_id' => '701', 'title' => 'יריעה', 'why' => 'לגג']] : null,
                    'whatsapp_shown' => ! $picked, 'whatsapp_clicked' => false, 'picked' => $picked ? ['701'] : null,
                    'created_at' => $yesterday, 'updated_at' => $yesterday,
                ]);
            }
        });
        $this->replies = [[
            'items' => [['id' => 'a1', 'score' => 5, 'note' => 'מדויק.'], ['id' => 'a2', 'score' => 3, 'note' => 'אין בחנות.'], ['id' => 'a9', 'score' => 1, 'note' => 'לא קיים.']],
            'summary' => 'יום טוב. רוב השאלות נענו.',
            'improvements' => ['להוסיף גנרטורים לקטלוג.'],
        ]];

        $run = app(ReviewSearchAsks::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertSame(AiProviderName::Anthropic, $this->calls[0]['provider'], 'another family than the OpenAI writer');
        $review = $this->inShop(fn () => AssistantAskReview::query()->where('status', AssistantAskReview::REVIEWED)->sole());
        $this->assertSame(80, $review->score, 'the average of 5 and 3, out of 100; an id it was not given is ignored');
        $this->assertSame(['asks' => 2, 'answered' => 1, 'no_match' => 1, 'whatsapp_shown' => 1, 'whatsapp_clicked' => 0, 'picked' => 1], $review->counts);
        $this->assertCount(2, $review->items);

        app(ReviewSearchAsks::class)->handle($this->shop->id);
        $this->assertCount(1, $this->calls, 'the same day is not reviewed twice');

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $page = $this->actingAs($merchant)->followingRedirects()->get("/merchant/{$this->shop->slug}/assistant/questions")->assertOk();
        $page->assertSee('יום טוב. רוב השאלות נענו.');
        $page->assertSee('להוסיף גנרטורים לקטלוג.');
        $page->assertDontSee('reviewNow', false);
        $page->assertDontSee('$0.', false);
    }

    public function test_a_reviewer_never_checks_its_own_family(): void
    {
        $this->inShop(fn () => AssistantSearchAsk::query()->create([
            'shop_id' => $this->shop->id, 'day' => now()->subDay()->toDateString(), 'question' => 'שאלה', 'products' => [], 'outcome' => 'answered', 'from' => 'model',
        ]));
        Settings::set('assistant.review_provider', 'openai');

        $this->assertSame('failed', app(ReviewSearchAsks::class)->handle($this->shop->id)->status->value);
        $this->assertSame([], $this->calls);
    }

    /** @param list<string> $products */
    private function ask(string $question, array $products): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/ask', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['question' => $question, 'vid' => self::VID, 'locale' => 'he', 'products' => $products], JSON_UNESCAPED_UNICODE));
    }

    /** @param list<array<string, mixed>> $events */
    private function events(array $events): void
    {
        $this->call('POST', '/api/v1/search/'.SiteKeys::site(self::TOKEN).'/events', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['events' => $events], JSON_UNESCAPED_UNICODE))->assertNoContent();
    }
}
