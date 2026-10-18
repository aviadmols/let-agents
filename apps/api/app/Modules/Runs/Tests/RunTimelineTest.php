<?php

namespace App\Modules\Runs\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every run of an agent is on the operator's timeline with its cost; the live answers as one line a day. */
final class RunTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_are_on_the_timeline_with_their_cost_and_live_answers_as_one_line_a_day(): void
    {
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();
        $run = Run::query()->create(['shop_id' => $shop->id, 'agent' => 'improvement.reviewer', 'action' => 'improvement.daily_review', 'status' => 'succeeded', 'trigger' => 'schedule',
            'started_at' => now()->subDay(), 'summary_key' => 'improvement::runs.quiet', 'cost_usd' => '0.4200']);
        Run::query()->create(['shop_id' => $shop->id, 'agent' => 'assistant.answerer', 'action' => 'assistant.answer', 'status' => 'succeeded', 'trigger' => 'webhook', 'started_at' => now()->subHours(2), 'cost_usd' => '0.0100']);
        Run::query()->create(['shop_id' => $shop->id, 'agent' => 'assistant.answerer', 'action' => 'assistant.answer', 'status' => 'succeeded', 'trigger' => 'webhook', 'started_at' => now()->subHour(), 'cost_usd' => '0.0200']);
        Run::query()->create(['shop_id' => $other->id, 'agent' => 'improvement.reviewer', 'action' => 'improvement.daily_review', 'status' => 'succeeded', 'trigger' => 'schedule', 'started_at' => now(), 'summary_key' => 'improvement::runs.quiet', 'cost_usd' => '9.0000']);

        $this->actingAs(User::factory()->operator()->create())->withHeader('Accept-Language', 'he')
            ->get('/operator/timeline?shop='.$shop->slug)
            ->assertOk()
            ->assertSee('data-timeline-entry="run.'.$run->id.'"', false)
            ->assertSee('אין משהו חדש')
            ->assertSee('$0.42')
            ->assertSee('2 תשובות לגולשים')
            ->assertSee('$0.45')
            ->assertDontSee('$9.00');

        $this->get('/operator/timeline?shop='.$shop->slug.'&kind=system')
            ->assertSee('2 תשובות לגולשים')
            ->assertDontSee('data-timeline-entry="run.'.$run->id.'"', false);
    }
}
