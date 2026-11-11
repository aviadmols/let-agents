<?php

namespace App\Core\Console;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Log;

/**
 * What a scheduled command run in the background says reaches the log. Laravel sends its output to /dev/null unless
 * told otherwise, so every nightly command in the background (the search index, the retrieval night, the daily review,
 * the leads' sweeps) left no line at all, not even that it failed (9-11.11). Its output, and what it logs to stderr,
 * goes to the scheduler process's own output instead (logging.schedule_output: /proc/1/fd/1 in the container, what
 * Railway reads), and its end is logged with its name and exit code. A command that chose its own output keeps it.
 * Off with an empty logging.schedule_output, or where the target cannot be written.
 */
final class ScheduleOutput
{
    public function starting(ScheduledTaskStarting $event): void
    {
        $task = $event->task;
        $target = (string) config('logging.schedule_output');

        if (! $task->runInBackground || $target === '' || $task->output !== $task->getDefaultOutput() || ! self::writable($target)) {
            return;
        }

        $task->appendOutputTo($target);
    }

    /**
     * Its end, with its exit code: written to the same output, as one JSON line the platform reads with its level.
     * Not through the log: Laravel runs schedule:finish with its own output in /dev/null, where a log line to stderr
     * went (11.11).
     */
    public function finished(ScheduledBackgroundTaskFinished $event): void
    {
        $task = $event->task;
        $failed = $task->exitCode !== 0;
        $line = [
            'level' => $failed ? 'error' : 'info',
            'message' => $failed ? 'Scheduled task failed in the background' : 'Scheduled task finished in the background',
            'task' => $task->description ?: $task->command,
            'exit_code' => $task->exitCode,
        ];
        $target = (string) config('logging.schedule_output');

        if ($target !== '' && self::writable($target)) {
            @file_put_contents($target, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);

            return;
        }

        $failed ? Log::error($line['message'], $line) : Log::info($line['message'], $line);
    }

    private static function writable(string $target): bool
    {
        return file_exists($target) ? is_writable($target) : is_dir(dirname($target)) && is_writable(dirname($target));
    }
}
