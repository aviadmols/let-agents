<?php

namespace Tests\Core;

use App\Core\Console\ScheduleOutput;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A scheduled command run in the background speaks to the log (it went to /dev/null: nine nightly commands left no
 * line, 9-11.11), and its end is logged with its exit code; a command in the foreground, or with its own output,
 * keeps its own.
 */
final class ScheduleOutputTest extends TestCase
{
    public function test_a_background_command_writes_to_the_log_and_its_end_is_logged(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'schedule-');
        config(['logging.schedule_output' => $target]);
        $schedule = app(Schedule::class);

        $background = $schedule->command('inspire')->runInBackground();
        $foreground = $schedule->command('inspire');
        $own = $schedule->command('inspire')->runInBackground()->sendOutputTo($own = tempnam(sys_get_temp_dir(), 'own-'));

        foreach ([$background, $foreground, $own] as $task) {
            event(new ScheduledTaskStarting($task));
        }

        $this->assertSame([$target, true], [$background->output, $background->shouldAppendOutput]);
        $this->assertSame($foreground->getDefaultOutput(), $foreground->output, 'the foreground is the scheduler\'s own output already');
        $this->assertNotSame($target, $own->output, 'a command that chose its output keeps it');

        Log::spy();
        $background->exitCode = 1;
        app(ScheduleOutput::class)->finished(new ScheduledBackgroundTaskFinished($background));
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['exit_code'] === 1);

        @unlink($target);
    }

    public function test_off_with_no_target(): void
    {
        config(['logging.schedule_output' => '']);
        $task = app(Schedule::class)->command('inspire')->runInBackground();

        event(new ScheduledTaskStarting($task));

        $this->assertSame($task->getDefaultOutput(), $task->output);
    }
}
