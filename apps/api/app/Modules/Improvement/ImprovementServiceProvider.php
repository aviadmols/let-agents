<?php

namespace App\Modules\Improvement;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Admin\Contracts\WritesPosts;
use App\Modules\Improvement\Console\ImprovementCommand;
use App\Modules\Improvement\Support\ImprovementHome;
use App\Modules\Improvement\Support\ImprovementTimeline;
use App\Modules\Improvement\Support\QuestionPosts;
use Illuminate\Console\Scheduling\Schedule;

final class ImprovementServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(WritesPosts::class, QuestionPosts::class);
        $this->app->tag([ImprovementTimeline::class], TimelineSource::TAG);
        // What this module shows on the shop's home and notices.
        $this->app->tag([ImprovementHome::class], HomeSource::TAG);
    }

    protected function bootModule(): void
    {
        // After the night's work (index, matching, scores at 04:15), so the review sees a whole day.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('improvement review --all')
                ->dailyAt('04:30')
                ->timezone('Asia/Jerusalem')
                ->name('improvement:review')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            ImprovementCommand::class,
        ];
    }
}
