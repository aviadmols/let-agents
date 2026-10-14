<?php

namespace App\Modules\Catalog;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\ScanDetails;
use App\Modules\Catalog\Console\SyncCatalogCommand;
use App\Modules\Catalog\Console\SyncChangedProductsCommand;
use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Events\CatalogSyncRequested;
use App\Modules\Catalog\Jobs\SyncCatalogJob;
use App\Modules\Catalog\Listeners\MoveCatalogLinks;
use App\Modules\Catalog\Support\CatalogProductKinds;
use App\Modules\Catalog\Support\CatalogScanDetails;
use App\Modules\Connections\Events\StoreAddressMoved;
use App\Modules\Runs\Enums\RunTrigger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // What this module learned, under its step of the store's scan.
        $this->app->tag([CatalogScanDetails::class], ScanDetails::TAG);
        $this->app->singleton(ProductKinds::class, CatalogProductKinds::class);
    }

    protected function bootModule(): void
    {
        Event::listen(StoreAddressMoved::class, MoveCatalogLinks::class);
        // A read asked for from another screen: the sync job, with the 20 minutes a large store needs.
        Event::listen(CatalogSyncRequested::class, fn (CatalogSyncRequested $event) => SyncCatalogJob::dispatch($event->shopId, RunTrigger::Manual));

        // Every connected shop with catalog.daily_sync on is read again at night, Israel time.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('catalog:sync --scheduled')
                ->dailyAt('02:30')
                ->timezone('Asia/Jerusalem')
                ->name('catalog:sync-daily')
                ->onOneServer();

            // And between nights, what each store changed (catalog.live_sync): a new product is
            // searchable within minutes. A few cheap questions to the store; nothing when nothing changed.
            $schedule->command('catalog:sync-changes')
                ->everyTenMinutes()
                ->name('catalog:sync-changes')
                ->withoutOverlapping(30)
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            SyncCatalogCommand::class,
            SyncChangedProductsCommand::class,
        ];
    }
}
