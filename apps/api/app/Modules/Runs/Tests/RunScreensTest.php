<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Filament\Operator\Resources\Runs\Pages\ListRuns;
use App\Modules\Runs\Models\RunCall;
use App\Modules\Tenancy\Models\Shop;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class RunScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_sees_runs_live_and_opens_one_in_both_languages(): void
    {
        $shop = Shop::factory()->create(['name' => 'Gueta Avigdor']);
        $run = app(RecordsRuns::class)->track(
            agent: 'connections.store_checker',
            action: 'connections.test',
            shopId: $shop->id,
            input: ['site_url' => 'https://guetaavigdor.test'],
            work: fn (RunContext $r) => $r->output(['http_status' => 200])->summary('connections::runs.connected', ['version' => '0.1.0', 'products' => '1,191']),
        );

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test(ListRuns::class)
            ->assertCanSeeTableRecords([$run])
            ->assertSee('Gueta Avigdor');

        // Beside the list: the run, and what each call to a model was sent and returned.
        RunCall::query()->create(['run_id' => $run->id, 'shop_id' => $shop->id, 'kind' => 'chat', 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'system' => 'You answer from the store pages.', 'sent' => json_encode(['question' => 'על מה הספר?', 'passages' => [['title' => 'מפגש עם עצמי בגיהינום']]], JSON_UNESCAPED_UNICODE), 'received' => '{"found": true}', 'ok' => true, 'input_tokens' => 1200, 'output_tokens' => 80, 'duration_ms' => 900]);
        $view = Livewire::test(ListRuns::class)->mountAction(TestAction::make('view')->table($run))->instance()->getTable()->getAction('view')->record($run);
        $this->assertNull($view->getUrl(), 'opens beside the list, not on a page of its own');
        $this->assertTrue($view->isModalSlideOver());
        $this->get("/operator/runs/{$run->id}")
            ->assertOk()
            ->assertSee('gpt-5.4-mini')
            ->assertSee('מפגש עם עצמי בגיהינום')
            ->assertSee('You answer from the store pages.');

        // As a flow: a search answer's code search, then the writer by what it does, with its own price.
        $files = glob(app_path('Modules/Assistant/Prompts/answer_site.v*.md'));
        $writer = (string) file_get_contents((string) end($files));
        $answer = app(RecordsRuns::class)->track(agent: 'assistant.site_answerer', action: 'assistant.answer_site', shopId: $shop->id, input: ['question' => 'על מה הספר?'],
            work: fn (RunContext $r) => $r->output(['context' => ['passages_found' => 9, 'passages_sent' => 4, 'products_shown' => 12, 'products_sent' => 3]]));
        RunCall::query()->create(['run_id' => $answer->id, 'shop_id' => $shop->id, 'kind' => 'chat', 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'system' => $writer, 'sent' => '{}', 'received' => '{}', 'ok' => true, 'input_tokens' => 2251, 'output_tokens' => 226, 'duration_ms' => 2047]);
        DB::table('ai_usage')->insert(['run_id' => $answer->id, 'shop_id' => $shop->id, 'agent' => 'assistant.site_answerer', 'action' => 'assistant.answer_site', 'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'input_tokens' => 2251, 'output_tokens' => 226, 'cost_usd' => 0.004059, 'day' => now()->toDateString(), 'created_at' => now()]);
        $this->get("/operator/runs/{$answer->id}")
            ->assertOk()
            ->assertSeeInOrder([__('runs::flow.found'), __('runs::prompts.assistant.answer_site')])
            ->assertSee(__('runs::flow.found_detail', ['passages' => '9', 'passages_sent' => '4', 'products' => '12', 'products_sent' => '3']))
            ->assertSee('$0.00406', false);

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->get("/operator/runs/{$run->id}")
                ->assertOk()
                ->assertSee(__('connections::agents.store_checker', [], $locale))
                ->assertSee(__('connections::runs.connected', ['version' => '0.1.0', 'products' => '1,191'], $locale))
                ->assertSee('guetaavigdor.test');
        }
    }

    public function test_merchants_cannot_open_the_activity_log(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/operator/runs')
            ->assertForbidden();
    }
}
