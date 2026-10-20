<?php

namespace App\Modules\Enrichment;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\FirstSteps;
use App\Modules\Admin\Contracts\HomeCards;
use App\Modules\Admin\Contracts\ScanDetails;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Enrichment\Actions\RereadPage;
use App\Modules\Enrichment\Console\CompleteLooksCommand;
use App\Modules\Enrichment\Console\EnrichmentCommand;
use App\Modules\Enrichment\Contracts\ReadsCards;
use App\Modules\Enrichment\Contracts\RereadsPages;
use App\Modules\Enrichment\Listeners\RebuildCards;
use App\Modules\Enrichment\Support\EnrichmentHome;
use App\Modules\Enrichment\Support\EnrichmentScanDetails;
use App\Modules\Enrichment\Support\FactNotes;
use App\Modules\Enrichment\Support\StoredCards;
use App\Modules\Enrichment\Support\WrittenSectionQuestions;
use App\Modules\Retrieval\Contracts\DocumentNotes;
use App\Modules\Retrieval\Contracts\SectionQuestions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

final class EnrichmentServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->tag([EnrichmentHome::class], HomeCards::CARDS_TAG);
        $this->app->tag([EnrichmentHome::class], FirstSteps::STEPS_TAG);
        // What this module learned, under its step of the store's scan.
        $this->app->tag([EnrichmentScanDetails::class], ScanDetails::TAG);
        // What the scan learned and approved joins the meaning vectors of products and posts.
        $this->app->tag([FactNotes::class], DocumentNotes::NOTES_TAG);
        // The questions each section of a page or post answers, for the section's second vector.
        $this->app->tag([WrittenSectionQuestions::class], SectionQuestions::TAG);
        // Another module may ask for one page to be read again; it gets the code readers only.
        $this->app->bind(RereadsPages::class, RereadPage::class);
        // The cards every model reads: one product, page or post, or the store, as one JSON.
        $this->app->bind(ReadsCards::class, StoredCards::class);
    }

    protected function bootModule(): void
    {
        // A sync's products are carded at once: a new product is read by every model minutes after it is published.
        Event::listen([CatalogUpdated::class, CatalogProductsChanged::class], RebuildCards::class);

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

            // Answers of batches handed to a provider: read as they end, through the day.
            $schedule->command('enrichment collect --scheduled')
                ->everyTenMinutes()
                ->name('enrichment:collect-batches')
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
            CompleteLooksCommand::class,
        ];
    }
}
