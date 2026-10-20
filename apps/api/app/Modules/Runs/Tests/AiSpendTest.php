<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every model call is in the ledger, and the operator reads spend by shop, model and day. */
final class AiSpendTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_writer_and_a_checker_are_two_rows_of_the_ledger(): void
    {
        $shop = Shop::factory()->create();

        app(RecordsRuns::class)->track(agent: 'assistant.site_answerer', action: 'assistant.answer_site', shopId: $shop->id, trigger: RunTrigger::Manual,
            work: function (RunContext $run): void {
                $run->usage('anthropic', 'claude-sonnet-5-5', 1200, 300, 0, 0.0081);
                $run->usage('openai', 'gpt-5.4-nano', 900, 20, 0, 0.0001);
            });

        $rows = DB::table('ai_usage')->orderBy('id')->get();
        $this->assertSame(['claude-sonnet-5-5', 'gpt-5.4-nano'], $rows->pluck('model')->all());
        $this->assertSame([1200, 900], $rows->pluck('input_tokens')->map(fn ($v) => (int) $v)->all());
        $this->assertSame($shop->id, $rows[0]->shop_id);
    }

    public function test_the_operator_reads_spend_by_shop_and_model_for_a_month_or_days(): void
    {
        $gueta = Shop::factory()->create(['name' => 'גואטה']);
        $other = Shop::factory()->create(['name' => 'חנות אחרת']);
        $row = fn (Shop $shop, string $model, float $cost, string $day): array => [
            'shop_id' => $shop->id, 'agent' => 'search.photo_reader', 'action' => 'search.read_photo', 'provider' => 'anthropic',
            'model' => $model, 'input_tokens' => 1000, 'output_tokens' => 100, 'cache_read_tokens' => 0, 'cost_usd' => $cost, 'day' => $day, 'created_at' => now(),
        ];
        DB::table('ai_usage')->insert([
            $row($gueta, 'claude-haiku-4-5', 0.40, '2026-10-02'),
            $row($gueta, 'claude-sonnet-5-5', 1.10, '2026-10-05'),
            $row($other, 'claude-haiku-4-5', 0.20, '2026-10-05'),
            $row($gueta, 'claude-haiku-4-5', 9.00, '2026-09-20'),
        ]);

        $this->actingAs(User::factory()->operator()->create());

        $this->get('/operator/ai-spend?month=2026-10')
            ->assertOk()
            ->assertSee('$1.70')
            ->assertSee('גואטה')
            ->assertSee('$1.50')
            ->assertSee('claude-sonnet-5-5')
            ->assertDontSee('$9.00');

        $this->get('/operator/ai-spend?month=2026-10&from=2026-10-05&to=2026-10-05&shop='.$gueta->id)
            ->assertOk()
            ->assertSee('$1.10')
            ->assertDontSee('חנות אחרת</summary>', false);

        $merchant = User::factory()->create();
        $merchant->attachShop($gueta);
        $this->actingAs($merchant)->get('/operator/ai-spend')->assertForbidden();
    }

    public function test_answering_shoppers_is_counted_apart_from_the_work_in_the_background(): void
    {
        $shop = Shop::factory()->create(['name' => 'סלע מאיר']);
        $row = fn (string $agent, float $cost): array => [
            'shop_id' => $shop->id, 'agent' => $agent, 'action' => 'x', 'provider' => 'openai',
            'model' => 'gpt-5.4-mini', 'input_tokens' => 1000, 'output_tokens' => 100, 'cache_read_tokens' => 0, 'cost_usd' => $cost, 'day' => '2026-10-05', 'created_at' => now(),
        ];
        DB::table('ai_usage')->insert([
            $row('assistant.site_answerer', 0.30),
            $row('thinking.set_finder', 0.20),
            $row('taxonomy.tagger', 1.25),
            $row('improvement.reviewer', 0.75),
        ]);

        $this->actingAs(User::factory()->operator()->create());

        $page = $this->get('/operator/ai-spend?month=2026-10')->assertOk();
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/data-spend-side="background".*?\$2\.00/s', $html, 'tagging and the daily review');
        $this->assertMatchesRegularExpression('/data-spend-side="live".*?\$0\.5000/s', $html, 'the chat and the sets');
        $this->assertMatchesRegularExpression('/data-spend-agent="thinking.set_finder" data-spend-agent-side="live"/', $html);
        $this->assertMatchesRegularExpression('/data-spend-agent="taxonomy.tagger" data-spend-agent-side="background"/', $html);
    }

    public function test_the_operator_reads_every_call_with_its_model_and_cost_the_latest_first(): void
    {
        $gueta = Shop::factory()->create(['name' => 'גואטה']);
        $other = Shop::factory()->create(['name' => 'חנות אחרת']);
        app(RecordsRuns::class)->track(agent: 'assistant.site_answerer', action: 'assistant.answer_site', shopId: $gueta->id, trigger: RunTrigger::Manual,
            work: function (RunContext $run): void {
                $run->usage('openai', 'gpt-5.4-mini', 2400, 310, 0, 0.00488);
                $run->usage('anthropic', 'claude-haiku-4-5', 1900, 40, 0, 0.0021);
            });
        DB::table('ai_usage')->insert(['shop_id' => $other->id, 'agent' => 'search.photo_reader', 'action' => 'search.read_photo', 'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5', 'input_tokens' => 5000, 'output_tokens' => 200, 'cache_read_tokens' => 0, 'cost_usd' => 0.75, 'day' => now()->subDays(30)->toDateString(), 'created_at' => now()->subDays(30)]);

        $this->actingAs(User::factory()->operator()->create());

        $this->get('/operator/model-calls')
            ->assertOk()
            ->assertSee(__('runs::calls.title'))
            ->assertSeeInOrder(['anthropic/claude-haiku-4-5', 'openai/gpt-5.4-mini'])
            ->assertSee('$0.00488')
            ->assertSee('2,400')
            ->assertSee('גואטה')
            ->assertDontSee('claude-sonnet-5-5</code>', false);

        $this->get('/operator/model-calls?from='.now()->subDays(40)->toDateString().'&model=anthropic|claude-sonnet-5-5')
            ->assertOk()
            ->assertSee('$0.75000')
            ->assertSee('חנות אחרת')
            ->assertDontSee('gpt-5.4-mini</code>', false);

        $merchant = User::factory()->create();
        $merchant->attachShop($gueta);
        $this->actingAs($merchant)->get('/operator/model-calls')->assertForbidden();
    }
}
