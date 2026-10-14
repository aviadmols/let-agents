<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Runs\Filament\Operator\Pages\PipelineFlow;
use App\Modules\Runs\Filament\Operator\Pages\ProcessLog;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A step of the pipeline opens to its runs, a run to its model calls, and a call to its prompt,
 * what was sent and what came back. Only the call opened carries its texts.
 */
final class ProcessLogTest extends TestCase
{
    use RefreshDatabase;

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        $shop = Shop::factory()->create(['name' => 'חנות בדיקה']);
        $this->run = Run::query()->create(['shop_id' => $shop->id, 'agent' => 'search.tagger', 'action' => 'search.write_tags', 'status' => 'succeeded', 'trigger' => 'schedule', 'started_at' => now(), 'input' => ['pages' => 3]]);
        RunCall::query()->create([
            'run_id' => $this->run->id, 'shop_id' => $shop->id, 'agent' => 'search.tagger', 'action' => 'search.write_tags',
            'kind' => 'chat', 'provider' => 'openai', 'model' => 'gpt-5.4-mini',
            'system' => 'PROMPT-TEXT: write tags', 'sent' => '{"page":"SENT-TEXT"}', 'received' => '{"tags":["RECEIVED-TEXT"]}',
            'input_tokens' => 120, 'output_tokens' => 30, 'duration_ms' => 840,
        ]);
        RunCall::query()->create(['kind' => 'vision', 'provider' => 'openai', 'model' => 'gpt-5.4-nano', 'sent' => 'OUTSIDE-TEXT', 'ok' => false, 'error' => 'timeout']);
    }

    public function test_each_step_of_the_pipeline_and_each_recent_run_opens_its_log(): void
    {
        Livewire::test(PipelineFlow::class)
            ->assertSee(ProcessLog::getUrl(['step' => 'search.write_tags']), false)
            ->assertSee(ProcessLog::getUrl(['step' => 'search.write_tags', 'run' => $this->run->id]));
    }

    public function test_a_step_shows_its_runs_a_run_its_calls_and_a_call_its_texts(): void
    {
        $page = Livewire::test(ProcessLog::class, ['step' => 'search.write_tags'])
            ->assertSeeHtml('data-process-run="'.$this->run->id.'"')
            ->assertSee('1')
            ->assertDontSee('PROMPT-TEXT');

        $page->call('openRun', $this->run->id)
            ->assertSee('"pages": 3')
            ->assertSee('gpt-5.4-mini')
            ->assertDontSee('PROMPT-TEXT')
            ->assertDontSee('OUTSIDE-TEXT');

        $call = RunCall::query()->where('run_id', $this->run->id)->sole();
        $page->call('openCall', $call->id)
            ->assertSee('PROMPT-TEXT: write tags')
            ->assertSee('SENT-TEXT')
            ->assertSee('RECEIVED-TEXT');
    }

    public function test_without_a_step_it_lists_the_latest_calls_those_outside_a_run_too(): void
    {
        $outside = RunCall::query()->whereNull('run_id')->sole();

        Livewire::test(ProcessLog::class)
            ->assertSee(__('runs::process.outside_run'))
            ->assertSee(__('runs::process.kinds.vision'))
            ->call('openCall', $outside->id)
            ->assertSee('OUTSIDE-TEXT')
            ->assertSee('timeout');
    }

    public function test_a_shop_manager_cannot_open_it(): void
    {
        $this->assertNotContains(ProcessLog::class, Filament::getPanel('merchant')->getPages());
    }
}
