<?php

namespace App\Modules\TryOn;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\TryOn\Console\PruneTryOnCommand;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Try-On was removed from the storefront and the panels (the owner's word). What stays: its tables
 * and their rows (what happened and what it cost), and the nightly deletion of the photos and
 * pictures it kept, on each photo's keeping date. The flags stay declared and off (`try_on.enabled`
 * off, `try_on.paused` on) for code that still asks them.
 */
final class TryOnServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneTryOnCommand::class]);
        }

        // Photos and pictures are kept for the shop's retention days, then deleted.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('try-on:prune')->dailyAt('03:50')->name('try-on:prune')->withoutOverlapping();
        });
    }
}
