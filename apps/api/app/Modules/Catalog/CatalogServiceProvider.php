<?php

namespace App\Modules\Catalog;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Catalog\Console\SyncCatalogCommand;
use App\Modules\Catalog\Listeners\MoveCatalogLinks;
use App\Modules\Connections\Events\StoreAddressMoved;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

final class CatalogServiceProvider extends ModuleServiceProvider
{
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
