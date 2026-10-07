<?php

namespace App\Modules\Analytics;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Analytics\Console\ComputeScoresCommand;
use App\Modules\Analytics\Filament\Widgets\ReportChart;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Support\BeaconSchema;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(BeaconSchema::class);
    }

    protected function bootModule(): void
    {
        // The charts on the report screens, mounted by name from the page view.
        Livewire::component(ReportChart::NAME, ReportChart::class);

        RateLimiter::for('widget-beacons', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('analytics.beacons_per_minute'))
            ->by('beacon:'.$request->ip().'|'.$request->route('site')));

        RateLimiter::for('plugin-api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('plugin:'.$request->route('site')));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [AnalyticsEvent::class]])->dailyAt('03:45')->name('analytics:prune');

            // What worked yesterday reorders the widget today, after the night's catalog read.
            $schedule->command('analytics:scores')
                ->dailyAt('04:15')
                ->timezone('Asia/Jerusalem')
                ->name('analytics:scores-daily')
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            ComputeScoresCommand::class,
        ];
    }
}
