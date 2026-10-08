<?php

namespace App\Modules\Knowledge;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Knowledge\Console\KnowledgeCommand;
use Illuminate\Console\Scheduling\Schedule;

final class KnowledgeServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // Last of the night, after the catalogue was synced and the scores computed, so the
        // snapshot describes the day that just finished rather than the one before it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('knowledge snapshot --all')
                ->dailyAt('05:10')
                ->timezone('Asia/Jerusalem')
                ->name('knowledge:snapshot-daily')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            // Once a week: did any of it help? Weekly rather than nightly because a day of one
            // shop's traffic cannot answer the question, and asking anyway invites a false yes.
            $schedule->command('knowledge measure --all')
                ->weeklyOn(0, '05:30')
                ->timezone('Asia/Jerusalem')
                ->name('knowledge:measure-weekly')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            // And what several shops of a trade found separately becomes the trade's.
            $schedule->command('knowledge promote')
                ->weeklyOn(0, '05:50')
                ->timezone('Asia/Jerusalem')
                ->name('knowledge:promote-weekly')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            KnowledgeCommand::class,
        ];
    }
}
