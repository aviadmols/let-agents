<?php

namespace App\Modules\Improvement\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Improvement\Actions\DraftAnswer;
use App\Modules\Improvement\Actions\DraftPost;
use App\Modules\Improvement\Filament\Operator\Pages\SiteSuggestions;
use App\Modules\Improvement\Jobs\DraftAnswerJob;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A suggestion becomes something shoppers meet only after the team approves it: a question's
 * answer as the site's own answer, a missing topic as a post a model drafted, a second model
 * checked, and the team edited.
 */
final class AiDraftsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array<string, mixed>> what the fake models were asked, in order */
    public array $calls = [];

    /** @var list<array<string, mixed>> replies, in order */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create(['name' => 'חומרי בניין', 'slug' => 'binyan', 'domain' => 'www.binyan.test']);
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly AiDraftsTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'system' => $system, 'input' => json_decode($user, true)];

                return new ModelReply(array_shift($this->test->replies) ?? [], 1000, 200);
            }
        });

        $this->inShop(function (): void {
            $floors = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'c1', 'name' => 'פרקטים', 'path' => ['פרקטים'], 'depth' => 0, 'hash' => 'c1', 'product_count' => 1]);
            foreach (['1' => 'פרקט למרפסת דגם טיק', '2' => 'שמן לעץ חיצוני', '3' => 'ברגים לגבס'] as $id => $title) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://www.binyan.test/p/{$id}", 'image_url' => "https://www.binyan.test/i/{$id}.jpg", 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['description' => 'תיאור של '.$title]]);
                if ($id === '1') {
                    $product->categories()->attach($floors->id);
                }
            }
        });
    }

    public function test_a_missing_topic_gets_a_post_the_team_edits_publishes_and_can_take_down(): void
    {
        $proposal = $this->proposal(ImprovementProposal::CONTENT_GAP, 'פרקט למרפסת', ['topic' => 'פרקט למרפסת', 'why' => 'מחפשים ואין.'], [['query' => 'פרקט למרפסת', 'searches' => 9]]);
        $this->replies = [
            ['title' => 'איך בוחרים פרקט למרפסת', 'body' => "## למה פרקט\nפרקט למרפסת דגם טיק עמיד בגשם.\n\n## מה עוד צריך\nשמן לעץ חיצוני שומר עליו.", 'products' => ['1', '2', '9']],
            ['ok' => false, 'problems' => ['לא לציין כמה שנים הפרקט מחזיק.']],
        ];

        $post = app(DraftPost::class)->handle($proposal);

        $this->assertNotNull($post);
        $this->assertSame(['1'], $post->products, 'only products the writer was given: the oil did not come up for the topic, 9 does not exist');
        $this->assertFalse($post->checked);
        $this->assertSame(['לא לציין כמה שנים הפרקט מחזיק.'], $post->problems);
        $this->assertSame(AiProviderName::Anthropic, $this->calls[0]['provider'], 'the analyst writes');
        $this->assertSame(AiProviderName::OpenAi, $this->calls[1]['provider'], 'the other family checks');
        $this->assertSame(['1'], array_column($this->calls[0]['input']['products'], 'id'), 'the writer gets the products the topic names, not the screws');
        $this->assertSame('איך בוחרים פרקט למרפסת', $this->calls[1]['input']['draft']['title']);
        $this->assertSame(ImprovementProposal::PENDING, $proposal->fresh()->status, 'a draft is not a decision');
        $this->assertSame(0, $this->inShop(fn () => CatalogContent::query()->count()), 'nothing is published by a draft');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])
            ->assertSet("titles.{$proposal->id}", 'איך בוחרים פרקט למרפסת')
            ->assertSee('לא לציין כמה שנים הפרקט מחזיק.')
            ->set("titles.{$proposal->id}", 'פרקט למרפסת: המדריך')
            ->set("drafts.{$proposal->id}", "## למה פרקט\nפרקט למרפסת דגם טיק עמיד בגשם.")
            ->call('approve', $proposal->id);

        $post = $post->fresh();
        $content = $this->inShop(fn () => CatalogContent::query()->sole());
        $this->assertSame([ImprovementProposal::APPLIED, ImprovementPost::PUBLISHED, 'פרקט למרפסת: המדריך'], [$proposal->fresh()->status, $post->status, $post->title], 'published as the team left it');
        $this->assertSame([CatalogContent::POST, 'la:'.$post->id, 'פרקט למרפסת: המדריך', ['1']], [$content->type, $content->external_id, $content->title, $content->product_external_ids], 'a guide of the store\'s own, among its content');
        $this->assertStringContainsString('/g/binyan/'.$post->id, (string) $content->url);

        $this->get($content->url)->assertOk()
            ->assertSee('פרקט למרפסת: המדריך')
            ->assertSee('<h2>למה פרקט</h2>', false)
            ->assertSee('פרקט למרפסת דגם טיק')
            ->assertSee('https://www.binyan.test/p/1', false)
            ->assertDontSee('ברגים לגבס');

        // Taken down: gone from the content and from its address.
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])->call('reject', $proposal->id);
        $this->assertSame(ImprovementPost::UNPUBLISHED, $post->fresh()->status);
        $this->assertNotNull($content->fresh()->removed_at);
        $this->get($content->url)->assertNotFound();
    }

    public function test_a_question_is_answered_on_the_site_as_the_team_left_it_and_never_with_a_placeholder(): void
    {
        $proposal = $this->proposal(ImprovementProposal::FAQ, 'האם יש משלוח לאילת?', ['question' => 'האם יש משלוח לאילת?', 'answer' => 'כן, תוך [לבדוק] ימים.'], [['question' => 'יש משלוח לאילת', 'asked' => 4]]);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        $page = Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])
            ->assertSet("drafts.{$proposal->id}", 'כן, תוך [לבדוק] ימים.')
            ->call('approve', $proposal->id);

        $this->assertSame(ImprovementProposal::PENDING, $proposal->fresh()->status, 'a placeholder is not an answer');
        $this->assertSame(0, $this->inShop(fn () => AssistantAnswer::query()->count()));

        $page->set("drafts.{$proposal->id}", 'כן, משלוח לאילת תוך 5 ימי עסקים.')->call('approve', $proposal->id);

        $answer = $this->inShop(fn () => AssistantAnswer::query()->sole());
        $this->assertSame([ImprovementProposal::APPLIED, 'כן, משלוח לאילת תוך 5 ימי עסקים.', 'team', AssistantAnswer::SHOWN, null, 4], [$proposal->fresh()->status, $answer->answer, $answer->source, $answer->status, $answer->product_id, $answer->asked_count]);
        $this->assertSame(AssistantAnswer::keyFor('האם יש משלוח לאילת?'), $answer->question_key, 'the next shopper asking it gets this answer');

        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])->call('reject', $proposal->id);
        $this->assertSame(AssistantAnswer::HIDDEN, $answer->fresh()->status, 'taken down, it is hidden');
    }

    public function test_a_writer_never_checks_its_own_post(): void
    {
        $proposal = $this->proposal(ImprovementProposal::CONTENT_GAP, 'פרקט למרפסת', ['topic' => 'פרקט למרפסת', 'why' => 'מחפשים ואין.'], [['query' => 'פרקט למרפסת', 'searches' => 9]]);
        Settings::set('improvement.auditor_provider', 'anthropic');

        $this->assertNull(app(DraftPost::class)->handle($proposal));
        $this->assertSame([], $this->calls, 'no model was asked');
        $this->assertSame('failed', Run::query()->where('agent', DraftPost::AGENT)->sole()->status->value);
    }

    public function test_a_question_gets_an_answer_written_from_the_stores_own_text_and_checked_by_the_other_family(): void
    {
        $this->app->instance(Passages::class, new class implements Passages
        {
            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                return [
                    ['source' => 'content', 'source_id' => 'x', 'external_id' => 'ship', 'title' => 'משלוחים', 'text' => 'משלוח לכל הארץ, כולל אילת, תוך 3 עד 5 ימי עסקים.', 'similarity' => 0.9],
                    ['source' => 'product', 'source_id' => 'y', 'external_id' => '1', 'title' => 'פרקט למרפסת דגם טיק', 'text' => 'פרקט עמיד.', 'similarity' => 0.5],
                ];
            }
        });
        $proposal = $this->proposal(ImprovementProposal::FAQ, 'האם יש משלוח לאילת?', ['question' => 'האם יש משלוח לאילת?', 'answer' => 'כן, תוך [לבדוק] ימים.'], [['question' => 'יש משלוח לאילת', 'asked' => 4]]);
        $this->replies = [
            ['answer' => 'כן, אנחנו שולחים גם לאילת, תוך 3 עד 5 ימי עסקים.', 'sources' => ['p1', 'p9']],
            ['ok' => true, 'problems' => []],
        ];

        $written = app(DraftAnswer::class)->handle($proposal);

        $this->assertSame(AiProviderName::OpenAi, $this->calls[0]['provider'], 'OpenAI writes by default');
        $this->assertSame(AiProviderName::Anthropic, $this->calls[1]['provider'], 'the other family checks');
        $this->assertSame(['p1', 'p2'], array_column($this->calls[0]['input']['passages'], 'id'), 'the writer gets the store\'s text with ids');
        $this->assertSame('כן, תוך [לבדוק] ימים.', $this->calls[0]['input']['draft']);
        $this->assertSame('כן, אנחנו שולחים גם לאילת, תוך 3 עד 5 ימי עסקים.', $this->calls[1]['input']['answer']);
        $fresh = $this->inShop(fn () => $written->fresh());
        $this->assertSame('כן, אנחנו שולחים גם לאילת, תוך 3 עד 5 ימי עסקים.', $fresh->detail['answer']);
        $this->assertSame(['משלוחים'], $fresh->detail['ai']['sources'], 'only passages it was given');
        $this->assertSame([], $fresh->detail['ai']['problems']);
        $this->assertSame(ImprovementProposal::PENDING, $fresh->status, 'an answer is not a decision');
        $this->assertSame('improvement.answer_writer', Run::query()->latest('id')->value('agent'));

        // On the screen: the new answer in the box, ready to edit and publish.
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])
            ->assertSet("drafts.{$proposal->id}", 'כן, אנחנו שולחים גם לאילת, תוך 3 עד 5 ימי עסקים.')
            ->assertSee('לכתוב שוב עם AI')
            ->assertSee('משלוחים');

        Queue::fake();
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])->call('draftAnswer', $proposal->id)->assertSee('מודל כותב את התשובה');
        Queue::assertPushed(DraftAnswerJob::class, 1);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  list<array<string, mixed>>  $evidence
     */
    private function proposal(string $kind, string $title, array $detail, array $evidence): ImprovementProposal
    {
        return $this->inShop(function () use ($kind, $title, $detail, $evidence): ImprovementProposal {
            $review = ImprovementReview::query()->create(['shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'status' => 'reviewed', 'evidence' => [], 'digest' => '']);

            return ImprovementProposal::query()->create([
                'shop_id' => $this->shop->id, 'review_id' => $review->id, 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'evidence' => $evidence,
                'fingerprint' => hash('sha256', $kind.$title), 'status' => ImprovementProposal::PENDING, 'proposed_by' => 'anthropic claude-sonnet-5-5',
            ]);
        });
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
