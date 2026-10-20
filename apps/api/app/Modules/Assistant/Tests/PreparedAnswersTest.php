<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Actions\PrepareStoreAnswers;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store connected today shows useful questions at once: the questions its own pages answer are
 * chosen in code and answered ahead, checked, kept with their sources, and asked again only when a
 * page they were written from changes.
 */
final class PreparedAnswersTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array{provider: AiProviderName, model: string}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    /** @var list<array<string, mixed>> */
    public array $passages = [];

    private CatalogContent $refunds;

    protected function setUp(): void
    {
        parent::setUp();
        // The topic check is its own model call, tested in GuardTest.
        Features::override('assistant.site_scope', false);

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => 'lat_'.str_repeat('d', 48),
        ]));
        $this->refunds = $this->article('policy-71', 'מדיניות החזרות', 'ניתן לקבל החזר כספי מלא תוך 14 יום מיום הקנייה, בתנאי שהמוצר לא נפגם.');
        $faq = $this->article('900', 'שאלות נפוצות', "האם אפשר לשלוח לחו\"ל?\nכרגע רק בישראל.\nאיך שומרים על התכשיט?\nבקופסה יבשה.");
        $this->inShop(function () use ($faq): void {
            $this->refunds->forceFill(['type' => 'page', 'url' => 'https://store.test/policies/refund-policy'])->save();
            $faq->forceFill(['type' => 'page', 'url' => 'https://store.test/pages/faq'])->save();
        });
        $this->passages = [['source' => 'content', 'source_id' => $this->refunds->id, 'external_id' => 'policy-71', 'title' => 'מדיניות החזרות', 'text' => (string) $this->refunds->body, 'similarity' => 0.8]];

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly PreparedAnswersTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'model' => $model];

                return new ModelReply(array_shift($this->test->replies) ?? ['found' => false], 700, 120);
            }
        });
        $this->app->instance(Passages::class, new class($test) implements Passages
        {
            public function __construct(private readonly PreparedAnswersTest $test) {}

            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                return array_slice($this->test->passages, 0, $limit);
            }
        });
    }

    public function test_the_stores_own_questions_are_answered_ahead_checked_and_kept_with_their_sources(): void
    {
        // Code chooses: returns (its refund page says so), and its FAQ page's questions as written. No delivery,
        // warranty or hours: no page talks about them.
        $questions = $this->inShop(fn () => app(PrepareStoreAnswers::class)->questions('he'));
        $this->assertSame(['אפשר להחזיר או להחליף?', 'האם אפשר לשלוח לחו"ל?', 'איך שומרים על התכשיט?'], $questions);

        $this->replies = [
            ['found' => true, 'answer' => 'כן, החזר כספי מלא תוך 14 יום מיום הקנייה.', 'refs' => ['s1']], ['supported' => true, 'on_topic' => true, 'safe' => true],
            ['found' => false], ['found' => false],
        ];
        $run = app(PrepareStoreAnswers::class)->handle($this->shop->id);

        $this->assertSame('assistant::runs.prepared', $run->summary_key);
        $this->assertSame(['questions' => 3, 'answered' => 1, 'not_answered' => 2], array_intersect_key($run->output, array_flip(['questions', 'answered', 'not_answered'])));
        $this->assertSame([AiProviderName::OpenAi, AiProviderName::Anthropic], array_column(array_slice($this->calls, 0, 2), 'provider'), 'one family writes, the other checks');

        $returns = $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->where('question', 'אפשר להחזיר או להחליף?')->sole());
        $this->assertSame([AssistantAnswer::ANSWERED, 'כן, החזר כספי מלא תוך 14 יום מיום הקנייה.'], [$returns->outcome, $returns->answer]);
        $this->assertNotNull($returns->shared_at, 'checked clean: every shopper gets it, without a model');
        $this->assertSame('https://store.test/policies/refund-policy', $returns->sources[0]['url'], 'kept with where it is written');
        $this->assertSame('bank', $this->askAsShopper('אפשר להחזיר או להחליף?')['from']);

        // The next sync, nothing changed: nothing is asked.
        $before = count($this->calls);
        $again = app(PrepareStoreAnswers::class)->handle($this->shop->id);
        $this->assertSame(3, $again->output['skipped']);
        $this->assertCount($before, $this->calls, 'never asked twice');

        // The refund page changed: its answer is written again from what it says now.
        $this->travel(1)->minutes();
        $this->inShop(fn () => $this->refunds->forceFill(['body' => 'ניתן לקבל החזר כספי מלא תוך 30 יום.'])->save());
        $this->passages[0]['text'] = 'ניתן לקבל החזר כספי מלא תוך 30 יום.';
        $this->replies = [['found' => true, 'answer' => 'כן, תוך 30 יום.', 'refs' => ['s1']], ['supported' => true, 'on_topic' => true, 'safe' => true], ['found' => false], ['found' => false]];
        app(PrepareStoreAnswers::class)->handle($this->shop->id);
        $this->assertSame('כן, תוך 30 יום.', $this->inShop(fn () => AnswerSiteQuestion::siteAnswers()->where('question', 'אפשר להחזיר או להחליף?')->sole()->answer));
        $this->assertSame(1, Run::query()->where('action', PrepareStoreAnswers::ACTION)->latest('id')->first()->output['answered']);
    }

    /** @return array<string, mixed> */
    private function askAsShopper(string $question): array
    {
        return app(AnswerSiteQuestion::class)->handle($this->shop->id, $question, hash('sha256', 'someone-else'));
    }
}
