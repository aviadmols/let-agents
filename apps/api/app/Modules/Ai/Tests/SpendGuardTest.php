<?php

namespace App\Modules\Ai\Tests;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Runs\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class SpendGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_call_that_would_pass_the_monthly_cap_is_refused_before_it_is_sent(): void
    {
        $this->travelTo(Carbon::parse('2026-09-17 12:00:00', 'UTC'));
        $guard = app(SpendGuard::class);

        $this->assertSame(50.0, $guard->cap(), 'fifty dollars a month until the operator changes it');

        $this->recordRun(49.5, '2026-09-03 08:00:00');
        $this->recordRun(80.0, '2026-08-30 08:00:00'); // last month
        $this->recordRun(null, '2026-09-10 08:00:00'); // work uploaded as answer files: no cost

        $this->assertEqualsWithDelta(49.5, $guard->spentThisMonth(), 0.0001);

        $guard->assertCanSpend(0.5);

        try {
            $guard->assertCanSpend(0.6);
            $this->fail('the call should have been refused');
        } catch (SpendCapReached $refused) {
            $this->assertEqualsWithDelta(49.5, $refused->spentUsd, 0.0001);
            $this->assertSame(50.0, $refused->capUsd);
            $this->assertStringContainsString('50.00', $refused->getMessage());
        }

        Settings::set('ai.monthly_spend_cap_usd', 100);
        $guard->assertCanSpend(0.6);
    }

    private function recordRun(?float $cost, string $at): void
    {
        Run::query()->create([
            'agent' => 'ai.test', 'action' => 'ai.test', 'status' => 'succeeded', 'trigger' => 'system',
            'provider' => $cost === null ? null : 'anthropic', 'cost_usd' => $cost,
            'started_at' => $at, 'created_at' => $at,
        ]);
    }
}
