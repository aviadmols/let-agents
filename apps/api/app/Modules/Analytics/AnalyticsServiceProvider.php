<?php

namespace App\Modules\Analytics;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\HomeNumbers;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Analytics\Console\ComputeScoresCommand;
use App\Modules\Analytics\Filament\Widgets\ReportChart;
use App\Modules\Analytics\Http\Middleware\RefuseBlockedAddresses;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Support\AnalyticsHomeNumbers;
use App\Modules\Analytics\Support\AnalyticsReport;
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
        $this->app->tag([AnalyticsReport::class], ShopReport::REPORT_TAG);
        $this->app->singleton(BeaconSchema::class);
        // The row of numbers at the top of the shop's home.
        $this->app->bind(HomeNumbers::class, AnalyticsHomeNumbers::class);
    }

    protected function bootModule(): void
    {
        // An address the shop blocked gets no search, no assistant and no beacon (the analytics screen).
        $this->app['router']->pushMiddlewareToGroup('api', RefuseBlockedAddresses::class);

        // The charts on the report screens, mounted by name from the page view.
        Livewire::component(ReportChart::NAME, ReportChart::class);

        RateLimiter::for('widget-beacons', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('analytics.beacons_per_minute'))
            ->by('beacon:'.$request->ip().'|'.$request->route('site')));

        RateLimiter::for('widget-visits', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('analytics.visits_per_minute'))
            ->by('visit:'.$request->ip().'|'.$request->route('site')));

        RateLimiter::for('plugin-api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('plugin:'.$request->route('site')));

        // A shopper completes a checkout or two, not dozens a minute: the floor under made-up orders.
        RateLimiter::for('pixel-orders', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('pixel:'.$request->ip().'|'.$request->route('site')));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [AnalyticsEvent::class, AnalyticsVisit::class]])->dailyAt('03:45')->name('analytics:prune');

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
