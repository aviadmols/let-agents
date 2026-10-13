<?php

namespace App\Modules\Runs;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Models\Run;
use App\Modules\Runs\Support\RunRecorder;
use App\Modules\Runs\Support\ScanHome;
use Illuminate\Console\Scheduling\Schedule;

final class RunsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // What this module shows on the shop's home and notices.
        $this->app->tag([ScanHome::class], HomeSource::TAG);
        $this->app->bind(RecordsRuns::class, RunRecorder::class);
    }

    protected function bootModule(): void
    {
        // Runs older than runs.retention_days are removed daily.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [Run::class]])->dailyAt('03:30')->name('runs:prune');
        });
    }
}
