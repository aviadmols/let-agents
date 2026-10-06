<?php

namespace App\Modules\Improvement;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Improvement\Console\ImprovementCommand;
use Illuminate\Console\Scheduling\Schedule;

final class ImprovementServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // After the night's work (index, matching, scores at 04:15), so the review sees a whole day.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('improvement review --all')
                ->dailyAt('04:30')
                ->timezone('Asia/Jerusalem')
                ->name('improvement:review')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            ImprovementCommand::class,
        ];
    }
}
