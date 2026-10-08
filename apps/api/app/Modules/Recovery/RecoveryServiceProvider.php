<?php

namespace App\Modules\Recovery;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Recovery\Console\ReportCommand;
use App\Modules\Recovery\Models\RecoveryCart;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class RecoveryServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // The popup's config is read on page loads; it is cached, so this only stops a flood.
        RateLimiter::for('cart', fn (Request $request): Limit => Limit::perMinute(60)->by('cart:'.$request->ip().'|'.$request->route('site')));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Every hour: the carts whose wait has passed get their report.
            $schedule->command('recovery:report --all')
                ->hourlyAt(7)
                ->name('recovery:report')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            $schedule->command('model:prune', ['--model' => [RecoveryCart::class]])
                ->dailyAt('03:50')
                ->timezone('Asia/Jerusalem')
                ->name('recovery:prune')
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            ReportCommand::class,
        ];
    }
}
