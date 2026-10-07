<?php

namespace App\Modules\Improvement\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Improvement\Actions\RunDailyReview;
use App\Modules\Improvement\Filament\Operator\Pages\SiteSuggestions;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The daily review: code gathers, one family proposes, the other checks, code applies only what
 * both accepted and the shop allows, and a quiet day costs nothing.
 */
final class DailyReviewTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{provider: AiProviderName, model: string, system: string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> replies, in order */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create(['name' => 'חומרי בניין']);
        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly DailyReviewTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'model' => $model, 'system' => $system, 'input' => json_decode($user, true)];

                return new ModelReply(array_shift($this->test->replies) ?? [], 1000, 200);
            }
        });

        $this->inShop(function (): void {
            CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => '1', 'name' => 'עצים', 'path' => ['עצים'], 'product_count' => 40, 'hash' => 'c1']);
            SearchIndex::query()->create(['shop_id' => $this->shop->id, 'hash' => 'h', 'items' => json_encode(['items' => [['id' => 'p:1', 'title' => 'עץ אורן מוקצע']]], JSON_UNESCAPED_UNICODE), 'counts' => [], 'built_at' => now()]);
            $day = now()->subDay()->toDateString();
            SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => $day, 'query' => 'קרש', 'searches' => 6, 'empty' => 6]);
            SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => $day, 'query' => 'פרקט למרפסת', 'searches' => 3, 'empty' => 3]);
            SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => $day, 'query' => 'עץ', 'searches' => 20, 'clicks' => 8, 'last_results' => 40]);
        });
    }

    public function test_one_family_proposes_the_other_checks_and_only_both_accepted_reaches_search(): void
    {
        Features::override('improvement.auto_synonyms', true, $this->shop->id);
        $this->replies = [
            ['proposals' => [
                ['kind' => 'synonym', 'refs' => ['s1'], 'term' => 'קרש', 'means' => 'עץ'],
                ['kind' => 'synonym', 'refs' => ['s1'], 'term' => 'קרש', 'means' => 'מתכת'],
                ['kind' => 'content_gap', 'refs' => ['s2'], 'topic' => 'פרקט למרפסת', 'why' => 'מחפשים ואין.'],
                ['kind' => 'faq', 'refs' => ['q9'], 'question' => 'שאלה בלי ראיות', 'answer' => 'x'],
            ]],
            ['verdicts' => [
                ['id' => 'p1', 'verdict' => 'accept', 'reason' => 'קרש הוא עץ.'],
                ['id' => 'p2', 'verdict' => 'refuse', 'reason' => 'לא באמת.'],
            ]],
        ];

        $run = app(RunDailyReview::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value, (string) $run->error);
        $this->assertCount(2, $this->calls);
        $this->assertSame([AiProviderName::Anthropic, 'claude-sonnet-5-5'], [$this->calls[0]['provider'], $this->calls[0]['model']]);
        $this->assertSame([AiProviderName::OpenAi, 'gpt-5.4-mini'], [$this->calls[1]['provider'], $this->calls[1]['model']]);
        $this->assertSame(['קרש', 'פרקט למרפסת'], array_column($this->calls[0]['input']['empty'], 'query'));
        $this->assertSame(['עצים'], $this->calls[0]['input']['words']);
        $this->assertCount(2, $this->calls[1]['input']['proposals'], 'code dropped the store word it does not use and the one with no evidence');

        $proposals = $this->inShop(fn () => ImprovementProposal::query()->orderBy('kind')->get()->keyBy('kind'));
        $this->assertSame(ImprovementProposal::APPLIED, $proposals['synonym']->status);
        $this->assertSame(ImprovementProposal::REFUSED, $proposals['content_gap']->status, 'no verdict is a refusal');
        $this->assertSame(['קרש', 'עץ', 'review'], array_values($this->inShop(fn () => SearchSynonym::query()->sole()->only(['term', 'means', 'origin']))));
        $this->assertSame('anthropic · claude-sonnet-5-5', $proposals['synonym']->proposed_by);
        $this->assertGreaterThan(0, (float) $run->cost_usd, 'both calls are priced on the run');

        $review = $this->inShop(fn () => ImprovementReview::query()->sole());
        $this->assertSame([ImprovementReview::REVIEWED, 2, 1, 1], [$review->status, $review->proposed, $review->accepted, $review->applied]);
        $this->assertStringContainsString('חומרי בניין', $review->digest);
        $this->assertStringContainsString('29 חיפושים, 9 בלי תוצאות', $review->digest, 'yesterday, counted in code');
    }

    public function test_the_same_evidence_is_never_paid_for_twice(): void
    {
        $this->replies = [['proposals' => []]];
        app(RunDailyReview::class)->handle($this->shop->id);
        $this->assertCount(1, $this->calls, 'nothing proposed, so the checker is not asked');

        $this->travel(1)->days();
        $run = app(RunDailyReview::class)->handle($this->shop->id);

        $this->assertCount(1, $this->calls, 'the same searches the next day: no model');
        $this->assertSame(ImprovementReview::QUIET, $this->inShop(fn () => ImprovementReview::query()->latest('day')->first())->status);
        $this->assertStringContainsString('אין משהו חדש', (string) $run->summary());

        // A problem that doubled comes back.
        $this->inShop(fn () => SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'query' => 'קרש', 'searches' => 9, 'empty' => 9]));
        $this->replies = [['proposals' => []]];
        $this->travel(1)->days();
        app(RunDailyReview::class)->handle($this->shop->id);
        $this->assertCount(2, $this->calls);
        $this->assertSame(['קרש'], array_column($this->calls[1]['input']['empty'], 'query'));
    }

    public function test_a_writer_never_checks_its_own_family(): void
    {
        Settings::set('improvement.auditor_provider', 'anthropic');

        $run = app(RunDailyReview::class)->handle($this->shop->id);

        $this->assertSame('failed', $run->status->value);
        $this->assertSame([], $this->calls, 'stopped before any model was asked');
        $this->assertStringContainsString('משפחה', (string) $run->summary());
    }

    public function test_the_team_decides_and_a_rejected_suggestion_is_not_made_again(): void
    {
        Settings::set('improvement.digest_email', 'team@store.test', $this->shop->id);
        config(['mail.default' => 'array']);
        Mail::fake();

        $this->replies = [
            ['proposals' => [['kind' => 'synonym', 'refs' => ['s1'], 'term' => 'קרש', 'means' => 'עץ'], ['kind' => 'content_gap', 'refs' => ['s2'], 'topic' => 'פרקט למרפסת', 'why' => 'מחפשים ואין.']]],
            ['verdicts' => [['id' => 'p1', 'verdict' => 'accept', 'reason' => 'כן.'], ['id' => 'p2', 'verdict' => 'accept', 'reason' => 'כן.']]],
        ];
        app(RunDailyReview::class)->handle($this->shop->id);
        $this->assertTrue($this->inShop(fn () => ImprovementReview::query()->sole()->mailed), 'the summary went to the address the shop gave');

        $pending = $this->inShop(fn () => ImprovementProposal::query()->get()->keyBy('kind'));
        $this->assertSame(ImprovementProposal::PENDING, $pending['synonym']->status, 'without auto_synonyms a synonym waits');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id])
            ->assertSeeInOrder(['ממתינות להחלטה', 'החלטות אחרונות'])
            ->assertSee('קרש = עץ')
            ->assertSee('פרקט למרפסת')
            ->call('approve', $pending['synonym']->id)
            ->call('reject', $pending['content_gap']->id);

        $this->assertSame(1, $this->inShop(fn () => SearchSynonym::query()->where('term', 'קרש')->count()));
        $this->assertSame(ImprovementProposal::REJECTED, $this->inShop(fn () => $pending['content_gap']->fresh()->status));

        // The same suggestion, on a day the problem doubled, is not made again.
        $this->inShop(fn () => SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => now()->addDay()->toDateString(), 'query' => 'פרקט למרפסת', 'searches' => 9, 'empty' => 9]));
        $this->travel(1)->days();
        $this->replies = [['proposals' => [['kind' => 'content_gap', 'refs' => ['s1'], 'topic' => 'פרקט למרפסת', 'why' => 'שוב.']]]];
        app(RunDailyReview::class)->handle($this->shop->id);
        $this->assertSame(1, $this->inShop(fn () => ImprovementProposal::query()->where('kind', 'content_gap')->count()));
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
