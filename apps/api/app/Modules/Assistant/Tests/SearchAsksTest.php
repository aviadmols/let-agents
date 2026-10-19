<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\BuildQualityBaseline;
use App\Modules\Assistant\Actions\ReviewSearchAsks;
use App\Modules\Assistant\Actions\RunQualityCases;
use App\Modules\Assistant\Filament\Operator\Pages\AnswerQuality;
use App\Modules\Assistant\Models\AssistantAskReview;
use App\Modules\Assistant\Models\AssistantQualityCase;
use App\Modules\Assistant\Models\AssistantQualityResult;
use App\Modules\Assistant\Models\AssistantQualityRun;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
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

        // The topic check is its own model call, tested in GuardTest; these test what follows it.
        Features::override('assistant.site_scope', false);

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
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
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
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => false],
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
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
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

    public function test_a_reply_that_is_not_an_answer_says_what_the_shop_offers_instead(): void
    {
        $notFound = [['found' => false, 'answer' => '', 'refs' => [], 'picks' => []]];

        // No WhatsApp number yet: the team cannot be offered, leaving details can.
        $this->replies = $notFound;
        $data = $this->ask('יש לכם מעלית לבית?', ['703'])->json('data');
        $this->assertSame('details', $data['no_answer']);

        // The shop chose only "not found": no WhatsApp, no details.
        Settings::set('assistant.no_answer', 'none', $this->shop->id);
        $this->replies = $notFound;
        $data = $this->ask('יש לכם גנרטור לבית?', ['703'])->json('data');
        $this->assertSame('none', $data['no_answer']);
        $this->assertFalse($data['whatsapp']);
        $this->assertFalse($this->inShop(fn () => AssistantSearchAsk::query()->findOrFail($data['ask_id'])->whatsapp_shown));

        // An answer carries none of it.
        $this->replies = [
            ['found' => true, 'answer' => 'יריעה ביטומנית.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'לגג שטוח.']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $this->assertArrayNotHasKey('no_answer', $this->ask('במה אוטמים גג שטוח', ['701'])->json('data'));
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

    public function test_an_answer_that_invents_or_scores_low_reaches_the_operators_list_of_every_shop(): void
    {
        $this->travel(1)->days();
        $yesterday = now()->subDay();
        $this->inShop(function () use ($yesterday): void {
            // Asked on the product's page: its answer is kept on the question's row, nowhere else.
            foreach ([['למה מתאימה היריעה?', 'היריעה מתאימה גם לבריכות שחייה.'], ['במה אוטמים גג', 'ביריעה ביטומנית.']] as [$q, $answer]) {
                AssistantSearchAsk::query()->create([
                    'shop_id' => $this->shop->id, 'day' => $yesterday->toDateString(), 'question' => $q, 'products' => ['701'],
                    'outcome' => 'answered', 'from' => 'model', 'answer' => $answer, 'whatsapp_shown' => false, 'whatsapp_clicked' => false,
                    'created_at' => $yesterday, 'updated_at' => $yesterday,
                ]);
            }
        });
        $this->replies = [[
            'items' => [['id' => 'a1', 'score' => 3, 'note' => 'לא כתוב במוצר.', 'flag' => 'invented'], ['id' => 'a2', 'score' => 5, 'note' => 'מדויק.', 'flag' => 'anything']],
            'summary' => 'יום סביר.',
            'improvements' => [],
        ]];

        app(ReviewSearchAsks::class)->handle($this->shop->id);

        $sent = json_decode($this->calls[0]['user'], true);
        $this->assertSame('היריעה מתאימה גם לבריכות שחייה.', $sent['items'][0]['answer'], 'the answer kept on the question reaches the reviewer');
        $this->assertStringContainsString('יריעה לאיטום גג בטון שטוח.', $sent['items'][0]['product_text'][0], 'with what the product page says, to tell an invention');
        $items = $this->inShop(fn () => AssistantAskReview::query()->sole()->items);
        $this->assertSame('invented', $items[0]['flag']);
        $this->assertArrayNotHasKey('flag', $items[1], 'a flag it does not know is dropped');

        $this->actingAs(User::factory()->operator()->create())->get('/operator/assistant/weak-answers')->assertOk()
            ->assertSee('היריעה מתאימה גם לבריכות שחייה.')
            ->assertSee(__('assistant::ui.weak.flags.invented'))
            ->assertDontSee('במה אוטמים גג');

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $this->actingAs($merchant)->get('/operator/assistant/weak-answers')->assertForbidden();
    }

    public function test_each_ask_keeps_how_it_was_answered_and_why_it_failed_for_the_operators_quality_screen(): void
    {
        $this->replies = [
            ['found' => true, 'answer' => 'מתחילים בפריימר ואז יריעה ביטומנית.', 'refs' => [], 'picks' => [['ref' => 'p2', 'why' => 'יסוד.']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
            // Products were sent and the writer found the answer in none of them.
            ['found' => false, 'answer' => '', 'refs' => [], 'picks' => []],
        ];

        $answered = $this->ask('מה כדאי לאיטום גג', ['701', '702', '703'])->json('data');
        $refused = $this->ask('יש לכם גנרטור שקט?', ['701', '702'])->json('data');

        $ask = $this->inShop(fn () => AssistantSearchAsk::query()->findOrFail($answered['ask_id']));
        $this->assertNotNull($ask->run_id, 'the run holds its calls and their ledger rows');
        $this->assertSame(AnswerSiteQuestion::promptVersion('answer_site'), $ask->prompt_version);
        $this->assertSame('kept', $ask->verdict);
        $this->assertNotNull($ask->writer_model);
        $this->assertNotNull($ask->checker_model);
        $this->assertSame(3, $ask->trace['context']['products_sent']);
        $this->assertNull($ask->failure);
        $this->assertGreaterThan(0, (float) $ask->cost_usd, 'what this answer cost');

        $miss = $this->inShop(fn () => AssistantSearchAsk::query()->findOrFail($refused['ask_id']));
        $this->assertSame('retrieval', $miss->failure, 'products were sent and none held the answer');
        $this->assertSame('skipped:no_answer', $miss->verdict, 'the writer found nothing: no check was needed');

        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->get('/operator/assistant/answer-quality')->assertOk()
            ->assertSee('יש לכם גנרטור שקט?')
            ->assertSee(__('assistant::ui.quality.reasons.retrieval'))
            ->assertDontSee('מה כדאי לאיטום גג', false);

        Livewire::actingAs($operator)->test(AnswerQuality::class)
            ->call('setFailure', $miss->id, 'missing_info')
            ->call('pick', 'missing_info')
            ->assertSee('יש לכם גנרטור שקט?')
            ->assertSee(__('assistant::ui.quality.by_team'));
        $this->assertSame('missing_info', $this->inShop(fn () => $miss->fresh()->failure_team), 'the team\'s reading is kept beside code\'s');

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $this->actingAs($merchant)->get('/operator/assistant/answer-quality')->assertForbidden();
    }

    public function test_the_operator_asks_again_with_another_prompt_and_keeps_fixed_questions_as_regression_cases(): void
    {
        $this->replies = [
            ['found' => false, 'answer' => '', 'refs' => [], 'picks' => []],
        ];
        $first = $this->ask('במה מתחילים איטום גג?', ['701', '702'])->json('data');
        $ask = $this->inShop(fn () => AssistantSearchAsk::query()->findOrFail($first['ask_id']));
        $versions = AnswerSiteQuestion::promptVersions('answer_site');
        $this->assertContains(AnswerSiteQuestion::promptVersion('answer_site'), $versions);
        $this->assertNotContains(5, $versions, 'the bench\'s writer is not a version to replay');

        // Asked again with an older writer prompt: the answer beside the first, nothing logged or saved.
        $older = min($versions);
        $this->replies = [
            ['found' => true, 'answer' => 'מתחילים בפריימר ביטומני ואז יריעה.', 'refs' => [], 'picks' => [['ref' => 'p2', 'why' => 'יסוד.']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $operator = User::factory()->operator()->create();
        $page = Livewire::actingAs($operator)->test(AnswerQuality::class)
            ->set('failure', '')
            ->call('toggle', $ask->id)
            ->set("replayVersion.{$ask->id}", (string) $older)
            ->call('replay', $ask->id);
        $replay = $page->get('replays')[$ask->id];
        $this->assertSame(['answered', $older, 'kept'], [$replay['outcome'], $replay['prompt'], $replay['verdict']]);
        $this->assertStringContainsString('פריימר', $replay['answer']);
        $this->assertSame(1, $this->inShop(fn () => AssistantSearchAsk::query()->count()), 'a replay is not a shopper\'s question');

        // Kept as a case: it must say "פריימר" and never "בריכה". Run with the current prompt.
        $page->set("caseForm.{$ask->id}", ['outcome' => 'answered', 'contains' => 'פריימר, יסוד', 'absent' => 'בריכה'])
            ->call('makeCase', $ask->id)
            ->set('shop', $this->shop->id);
        $this->replies = [
            ['found' => true, 'answer' => 'מתחילים ביסוד ביטומני, ומתאים גם לבריכה.', 'refs' => [], 'picks' => [['ref' => 'p2', 'why' => 'יסוד.']]],
            ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true],
        ];
        $page->call('runCases');

        $case = $this->inShop(fn () => AssistantQualityCase::query()->sole());
        $this->assertSame(['outcome' => 'answered', 'contains' => ['פריימר', 'יסוד'], 'absent' => ['בריכה']], $case->expect);
        $this->assertFalse($case->last_passed);
        $this->assertSame(['said:בריכה'], $case->last_problems, 'it said what it must not; "יסוד" was enough of the words to say');
        $this->assertSame(AnswerSiteQuestion::promptVersion('answer_site'), $case->last_prompt_version);
        $page->set('tab', 'cases')->assertSee('במה מתחילים איטום גג?')->assertSee(__('assistant::ui.quality.failed_case'));
    }

    public function test_a_case_runs_on_its_frozen_data_or_on_todays_and_is_checked_beyond_its_words(): void
    {
        $answer = fn (string $text, array $check = ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true]): array => [
            ['found' => true, 'answer' => $text, 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'יריעה לגג.']]], $check,
        ];
        $this->replies = $answer('יריעה ביטומנית לגג בטון שטוח.');
        $asked = $this->ask('במה אוטמים גג בטון?', ['701'])->json('data');
        $ask = $this->inShop(fn () => AssistantSearchAsk::query()->findOrFail($asked['ask_id']));

        $operator = User::factory()->operator()->create();
        $page = Livewire::actingAs($operator)->test(AnswerQuality::class)->set('failure', '')
            ->set("caseForm.{$ask->id}", ['outcome' => 'answered', 'picks_right' => true, 'supported' => true])
            ->call('makeCase', $ask->id);
        $case = $this->inShop(fn () => AssistantQualityCase::query()->sole());
        $this->assertSame(['outcome' => 'answered', 'picks' => ['701'], 'supported' => true], $case->expect, 'the products it offered, and the checker\'s word, beyond the words');
        $this->assertNotNull($case->snapshot_at);
        $this->assertStringContainsString('יריעה לאיטום גג בטון שטוח.', json_encode($case->snapshot, JSON_UNESCAPED_UNICODE));

        // The catalogue changes after the case was kept.
        $this->inShop(fn () => CatalogProduct::query()->where('external_id', '701')->update(['payload' => ['description' => 'יריעה חדשה עם ציפוי אלומיניום.']]));

        $this->calls = [];
        $this->replies = $answer('יריעה ביטומנית לגג.');
        $frozen = app(RunQualityCases::class)->handle($this->shop->id, null, null, AssistantQualityRun::FROZEN);
        $this->assertStringContainsString('יריעה לאיטום גג בטון שטוח.', $this->calls[0]['user'], 'on the data of then: a prompt is compared on the same data');
        $this->assertStringNotContainsString('ציפוי אלומיניום', $this->calls[0]['user']);
        $this->assertSame(['ran' => 1, 'passed' => 1, 'expected' => 1], array_intersect_key((array) $frozen->totals, array_flip(['ran', 'passed', 'expected'])));

        // On today's data, and the checker now takes the answer away: the words alone would not see it.
        $this->calls = [];
        $this->replies = $answer('יריעה עם ציפוי אלומיניום.', ['supported' => false, 'on_topic' => true, 'safe' => true, 'picks_fit' => true]);
        $live = app(RunQualityCases::class)->handle($this->shop->id, null, null, AssistantQualityRun::LIVE);
        $this->assertStringContainsString('ציפוי אלומיניום', $this->calls[0]['user'], 'on today\'s data: the same question against the store as it is now');
        $result = AssistantQualityResult::query()->where('run_id', $live->id)->sole();
        $this->assertFalse($result->passed);
        $this->assertContains('unsupported:answer_removed', $result->problems);
        $this->assertSame(1, (int) $live->totals['ran']);
        $page->set('tab', 'cases')->assertSee(__('assistant::ui.quality.modes.live'))->assertSee(__('assistant::ui.quality.runs_title'));
    }

    public function test_the_baseline_is_real_questions_from_every_shop_and_every_run_is_compared_with_the_one_before(): void
    {
        foreach ([['במה אוטמים גג?', 'answered', 4], ['יש לכם גנרטור?', 'no_info', 2], ['מה מזג האוויר?', 'out_of_scope', 1], ['במה אוטמים גג', 'answered', 1]] as [$q, $outcome, $times]) {
            for ($i = 0; $i < $times; $i++) {
                $this->inShop(fn () => AssistantSearchAsk::query()->create(['shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'question' => $q, 'products' => ['701'], 'outcome' => $outcome, 'from' => 'model']));
            }
        }

        $added = app(BuildQualityBaseline::class)->handle(10);
        $cases = $this->inShop(fn () => AssistantQualityCase::query()->orderBy('question')->get());

        $this->assertSame([$this->shop->id => 3], $added, 'each question once: "במה אוטמים גג?" and "במה אוטמים גג" are one');
        $this->assertTrue($cases->every(fn (AssistantQualityCase $c): bool => $c->kind === AssistantQualityCase::BASELINE && $c->snapshot !== null && $c->expect === []));
        $this->assertSame([], app(BuildQualityBaseline::class)->handle(10), 'built again, nothing is added twice');

        $reply = fn (): array => [['found' => true, 'answer' => 'יריעה ביטומנית.', 'refs' => [], 'picks' => [['ref' => 'p1', 'why' => 'לגג.']]], ['supported' => true, 'on_topic' => true, 'safe' => true, 'picks_fit' => true]];
        // The scope check is off here: each case is a writer and a checker.
        $this->replies = [...$reply(), ...$reply(), ...$reply()];
        $first = app(RunQualityCases::class)->handle(null, null, null, AssistantQualityRun::FROZEN, AssistantQualityCase::BASELINE, 'before');
        $this->replies = [...$reply(), ['found' => false, 'answer' => '', 'refs' => [], 'picks' => []], ...$reply()];
        $second = app(RunQualityCases::class)->handle(null, null, null, AssistantQualityRun::FROZEN, AssistantQualityCase::BASELINE, 'after');

        $this->assertSame(100.0, (float) $first->totals['answered']);
        $this->assertLessThan(100.0, (float) $second->totals['answered']);
        $this->assertSame(0, (int) $first->totals['expected'], 'a baseline case without an expectation is measured, not passed or failed');

        $runs = Livewire::actingAs(User::factory()->operator()->create())->test(AnswerQuality::class)->set('tab', 'cases')->instance()->runs();
        $this->assertSame('after', $runs[0]['label']);
        $this->assertSame(100.0, (float) $runs[0]['before']['answered'], 'each run beside the one before it');
    }

    public function test_cases_are_imported_from_a_file_with_their_data_and_no_model_call(): void
    {
        $this->artisan('assistant:quality-cases', ['shop' => $this->shop->slug, '--import' => base_path('app/Modules/Assistant/resources/evals/gueta-hedge-trimmer-620w.json')])
            ->expectsOutputToContain('5 cases imported')->assertSuccessful();

        $cases = $this->inShop(fn () => AssistantQualityCase::query()->get());
        $this->assertCount(5, $cases);
        $this->assertTrue($cases->every(fn (AssistantQualityCase $c): bool => $c->kind === AssistantQualityCase::REGRESSION && $c->snapshot_at !== null));
        $this->assertSame(['outcome' => 'answered', 'contains' => ['620'], 'supported' => true], $cases->firstWhere('question', 'מה ההספק של גוזם גדר חי חשמלי 620W?')->expect);
        $this->assertSame([], $this->calls, 'importing asks no model');
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
