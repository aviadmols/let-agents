<?php

namespace App\Modules\Alerts;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Ai\Events\SpendCapHit;
use App\Modules\Alerts\Console\SweepAlertsCommand;
use App\Modules\Alerts\Listeners\RememberSpendCap;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * The team emailed when something in the system goes wrong: a budget near its end or spent, work failing again and
 * again or stopped, a queue that does not move, a store not synced (FindProblems, every five minutes), each problem
 * once (TellProblems).
 */
final class AlertsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        Event::listen(SpendCapHit::class, RememberSpendCap::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('alerts:sweep')
                ->everyFiveMinutes()
                ->name('alerts:sweep')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }

    protected function moduleCommands(): array
    {
        return [SweepAlertsCommand::class];
    }
}
