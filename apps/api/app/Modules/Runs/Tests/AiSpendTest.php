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
}
