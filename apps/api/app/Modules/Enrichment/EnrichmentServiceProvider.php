<?php

namespace App\Modules\Enrichment;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\ScanDetails;
use App\Modules\Enrichment\Actions\RereadPage;
use App\Modules\Enrichment\Console\EnrichmentCommand;
use App\Modules\Enrichment\Contracts\RereadsPages;
use App\Modules\Enrichment\Support\EnrichmentScanDetails;
use Illuminate\Console\Scheduling\Schedule;

final class EnrichmentServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // What this module learned, under its step of the store's scan.
        $this->app->tag([EnrichmentScanDetails::class], ScanDetails::TAG);
        // Another module may ask for one page to be read again; it gets the code readers only.
        $this->app->bind(RereadsPages::class, RereadPage::class);
    }

    protected function bootModule(): void
    {
        // Every night, after the catalogue has been synced: everything code can read about a shop,
        // read again, skipping what has not changed. And once a week, early Sunday Israel time, the
        // audit samples a few articles and asks whether code read them well — it only ever proposes.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('enrichment nightly --scheduled')
                ->dailyAt('03:10')
                ->timezone('Asia/Jerusalem')
                ->name('enrichment:nightly')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            $schedule->command('enrichment audit --scheduled')
                ->weeklyOn(0, '04:40')
                ->timezone('Asia/Jerusalem')
                ->name('enrichment:audit-weekly')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            EnrichmentCommand::class,
        ];
    }
}
