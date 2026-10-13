<?php

namespace App\Modules\Catalog;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\ScanDetails;
use App\Modules\Catalog\Console\SyncCatalogCommand;
use App\Modules\Catalog\Listeners\MoveCatalogLinks;
use App\Modules\Catalog\Support\CatalogScanDetails;
use App\Modules\Connections\Events\StoreAddressMoved;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // What this module learned, under its step of the store's scan.
        $this->app->tag([CatalogScanDetails::class], ScanDetails::TAG);
    }

    protected function bootModule(): void
    {
        Event::listen(StoreAddressMoved::class, MoveCatalogLinks::class);

        // Every connected shop with catalog.daily_sync on is read again at night, Israel time.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('catalog:sync --scheduled')
                ->dailyAt('02:30')
                ->timezone('Asia/Jerusalem')
                ->name('catalog:sync-daily')
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            SyncCatalogCommand::class,
        ];
    }
}
