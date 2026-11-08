<?php

namespace App\Modules\Alerts\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class SchedulerBeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_tells_a_monitor_whether_the_scheduler_still_starts_its_commands(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 12:00:00', 'UTC'));

        // never beat: nothing has shown the scheduler alive
        $this->getJson('/api/v1/up/scheduler')->assertStatus(503)->assertJson(['ok' => false, 'last' => null]);

        $this->artisan('alerts:beat')->assertSuccessful();
        $this->getJson('/api/v1/up/scheduler')->assertOk()->assertJson(['ok' => true]);

        // within alerts.scheduler_minutes (5) it stays alive; past it, the monitor is told
        $this->travel(5)->minutes();
        $this->getJson('/api/v1/up/scheduler')->assertOk();
        $this->travel(1)->minutes();
        $this->getJson('/api/v1/up/scheduler')->assertStatus(503)->assertJson(['ok' => false, 'last' => '2026-11-08T12:00:00+00:00']);
    }

    public function test_the_beat_is_its_own_process_every_minute_like_the_commands_it_vouches_for(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'alerts:beat'));

        $this->assertNotNull($event, 'alerts:beat is scheduled');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertFalse($event->runInBackground);
    }
}
