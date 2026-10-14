<?php

namespace App\Modules\TryOn;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\TryOn\Console\PruneTryOnCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Try-On: a product on the shopper or in their room (docs/try-on.md, ADR 0012). Off for every shop
 * until it is turned on (`try_on.enabled`).
 */
final class TryOnServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        // A photo or a try costs money: a few a minute from one address to one shop.
        RateLimiter::for('try-on', fn (Request $request): Limit => Limit::perMinute(10)->by('try-on:'.$request->ip().'|'.$request->route('site')));

        if ($this->app->runningInConsole()) {
            $this->commands([PruneTryOnCommand::class]);
        }

        // Photos and pictures are kept for the shop's retention days, then deleted.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('try-on:prune')->dailyAt('03:50')->name('try-on:prune')->withoutOverlapping();
        });
    }
}
