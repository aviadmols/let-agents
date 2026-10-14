<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Runs\Filament\Operator\Pages\LearningJournal;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use App\Modules\Runs\Support\LearningAgents;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the learning and checking agents found stays forever, with every model call, and is seen in
 * one place; what the live agents asked of a model goes after its days.
 */
final class LearningJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_learning_agents_are_read_from_the_registry(): void
    {
        $actions = LearningAgents::actions();

        $this->assertContains('improvement.daily_review', $actions, 'the nightly analyst');
        $this->assertContains('assistant.review_asks', $actions, 'the reviewer of search questions');
        $this->assertContains('enrichment.audit_content', $actions, 'the weekly auditor');
        $this->assertNotContains('assistant.answer', $actions, 'a live answer is not learning');
    }

    public function test_their_runs_and_calls_outlive_the_days_and_a_live_agents_do_not(): void
    {
        $old = now()->subYears(2);
        $make = function (string $agent, string $action) use ($old): Run {
            $run = Run::query()->create(['agent' => $agent, 'action' => $action, 'status' => 'succeeded', 'trigger' => 'schedule', 'started_at' => $old, 'summary_key' => null]);
            $run->forceFill(['created_at' => $old])->save();
            RunCall::query()->create(['run_id' => $run->id, 'agent' => $agent, 'action' => $action, 'kind' => 'chat', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'system' => 'PROMPT', 'sent' => 'SENT', 'received' => '{"verdict":"ok"}'])
                ->forceFill(['created_at' => $old])->save();

            return $run;
        };

        $learned = $make('improvement.reviewer', 'improvement.daily_review');
        $live = $make('assistant.answerer', 'assistant.answer');
        RunCall::query()->create(['kind' => 'vision', 'provider' => 'openai', 'model' => 'm', 'sent' => 'OUTSIDE'])->forceFill(['created_at' => $old])->save();

        $this->artisan('model:prune', ['--model' => [RunCall::class]])->assertSuccessful();
        $this->artisan('model:prune', ['--model' => [Run::class]])->assertSuccessful();

        $this->assertTrue($learned->fresh() !== null, 'the analyst\'s run stays');
        $this->assertSame(['SENT'], RunCall::query()->pluck('sent')->all(), 'only the learning agent\'s call stays: not the live one, not one outside a run');
        $this->assertNull($live->fresh());

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        Livewire::test(LearningJournal::class)
            ->assertSeeHtml('data-learning-agent="improvement.reviewer"')
            ->assertSeeHtml('data-learning-run="'.$learned->id.'"')
            ->call('focus', 'assistant.ask_reviewer')
            ->assertDontSeeHtml('data-learning-run="'.$learned->id.'"');
    }
}
