<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\DraftTeamAnswer;
use App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\Question;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Retrieval\Contracts\Passages;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The team asks for a draft of its answer: a model writes it from the product, the store's text
 * and the team's own words, the other family checks it, code flags a number nothing said, and
 * nothing is published until the team publishes, never with a "[לבדוק: ...]" left in it.
 */
final class TeamDraftTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $replies = [];

    private AssistantAnswer $question;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly TeamDraftTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->calls[] = ['provider' => $provider, 'system' => $system, 'input' => json_decode($user, true)];

                return new ModelReply(array_shift($this->test->replies) ?? [], 500, 80);
            }
        });
        $this->app->instance(Passages::class, new class implements Passages
        {
            public function near(string $shopId, string $text, array $sources, int $limit): array
            {
                return [['source' => 'content', 'source_id' => 'c1', 'external_id' => '7', 'title' => 'אחריות ושירות', 'text' => 'לכל המכשירים אחריות יבואן.', 'similarity' => 0.8]];
            }
        });

        $this->buildShop();
        $drill = $this->product('500', 'מקדחה רוטטת 18V', 'מקדחה נטענת', []);
        $this->question = $this->inShop(fn () => AssistantAnswer::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $drill->id, 'question_key' => Question::key('כמה שנים אחריות?'), 'question' => 'כמה שנים אחריות?',
            'outcome' => AssistantAnswer::NO_INFO, 'prompt_version' => 2, 'asked_count' => 4, 'last_asked_at' => now(),
        ]));

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_the_team_words_become_a_checked_draft_in_the_box_and_nothing_is_published(): void
    {
        $this->replies = [
            ['answer' => 'למקדחה אחריות יבואן לשנתיים, ו־3 חודשים על הסוללה.', 'sources' => ['p1', 'p9']],
            ['ok' => true, 'problems' => []],
        ];

        Livewire::test(ShopQuestions::class, ['shop' => $this->shop->id])
            ->assertSee('ניסוח עם AI')
            ->set('drafts.'.$this->question->id, 'שנתיים אחריות')
            ->call('draft', $this->question->id)
            ->assertSet('drafts.'.$this->question->id, 'למקדחה אחריות יבואן לשנתיים, ו־3 חודשים על הסוללה.')
            ->assertSee('המספר 3 לא מופיע', false);

        $this->assertSame(AiProviderName::OpenAi, $this->calls[0]['provider'], 'the writer');
        $this->assertSame(AiProviderName::Anthropic, $this->calls[1]['provider'], 'the other family checks');
        $this->assertSame('שנתיים אחריות', $this->calls[0]['input']['notes'], 'the team words are the writer input');
        $this->assertSame('מקדחה רוטטת 18V', $this->calls[0]['input']['page']['title']);
        $this->assertSame('אחריות ושירות', $this->calls[0]['input']['passages'][0]['title']);
        $this->assertSame(AssistantAnswer::NO_INFO, $this->inShop(fn () => $this->question->fresh()->outcome), 'a draft publishes nothing');

        // The same question and words again: from the kept draft, no model.
        app(DraftTeamAnswer::class)->handle($this->shop->id, $this->question->id, 'שנתיים אחריות');
        $this->assertCount(2, $this->calls);
    }

    public function test_a_draft_with_a_part_left_to_check_is_not_published(): void
    {
        $this->replies = [['answer' => 'יש אחריות יבואן של [לבדוק: כמה שנים].', 'sources' => ['p1']], ['ok' => true, 'problems' => []]];

        $page = Livewire::test(ShopQuestions::class, ['shop' => $this->shop->id])->call('draft', $this->question->id)->call('answer', $this->question->id);
        $this->assertSame(AssistantAnswer::NO_INFO, $this->inShop(fn () => $this->question->fresh()->outcome));

        $page->set('drafts.'.$this->question->id, 'יש אחריות יבואן של שנתיים.')->call('answer', $this->question->id);
        $this->assertSame([AssistantAnswer::ANSWERED, 'team'], $this->inShop(fn () => [$this->question->fresh()->outcome, $this->question->fresh()->source]));
    }

    public function test_it_stops_at_the_daily_cap_and_when_turned_off(): void
    {
        Settings::set('assistant.team_drafts_per_day', 0, $this->shop->id);
        $this->assertSame(['refused' => 'limit'], app(DraftTeamAnswer::class)->handle($this->shop->id, $this->question->id, 'x'));

        Features::override('assistant.team_drafts', false, $this->shop->id);
        $this->assertSame(['refused' => 'off'], app(DraftTeamAnswer::class)->handle($this->shop->id, $this->question->id, 'x'));
        Livewire::test(ShopQuestions::class, ['shop' => $this->shop->id])->assertDontSee('ניסוח עם AI');
        $this->assertSame([], $this->calls);
    }
}
