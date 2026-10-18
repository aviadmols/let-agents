<?php

namespace App\Modules\Runs;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Runs\Contracts\CallJournal;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Models\RunCall;
use App\Modules\Runs\Support\CallLog;
use App\Modules\Runs\Support\RunRecorder;
use App\Modules\Runs\Support\RunStack;
use App\Modules\Runs\Support\RunTimeline;
use App\Modules\Runs\Support\ScanHome;
use Illuminate\Console\Scheduling\Schedule;

final class RunsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->tag([RunTimeline::class], TimelineSource::TAG);
        // What this module shows on the shop's home and notices.
        $this->app->tag([ScanHome::class], HomeSource::TAG);
        $this->app->bind(RecordsRuns::class, RunRecorder::class);
        // The runs open in this request; scoped so Octane never carries one into the next.
        $this->app->scoped(RunStack::class);
        $this->app->bind(CallJournal::class, CallLog::class);
    }

    protected function bootModule(): void
    {
        // Runs older than runs.retention_days are removed daily.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [Run::class]])->dailyAt('03:30')->name('runs:prune');
            // Prompts and replies are kept runs.call_log_days, shorter than the runs.
            $schedule->command('model:prune', ['--model' => [RunCall::class]])->dailyAt('03:35')->name('runs:prune-calls');
        });
    }
}
