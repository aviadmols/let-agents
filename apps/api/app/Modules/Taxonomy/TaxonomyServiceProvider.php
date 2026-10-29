<?php

namespace App\Modules\Taxonomy;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Enrichment\Contracts\ProductMeaning;
use App\Modules\Enrichment\Contracts\ShopRules;
use App\Modules\Search\Contracts\ProductTags;
use App\Modules\Search\Contracts\RelatedByTags;
use App\Modules\Taxonomy\Console\FixRangesCommand;
use App\Modules\Taxonomy\Console\TagChangesCommand;
use App\Modules\Taxonomy\Console\TaxonomyCommand;
use App\Modules\Taxonomy\Listeners\TagWhatChanged;
use App\Modules\Taxonomy\Support\Meaning;
use App\Modules\Taxonomy\Support\RelatedProducts;
use App\Modules\Taxonomy\Support\Rules;
use App\Modules\Taxonomy\Support\TagWords;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

/**
 * A store's tag bank and its products' tags, for any kind of store (BuildTagBank, AssignTags,
 * MergeTagProposals). A shop with taxonomy.tagging on tags what it changes by itself (TagWhatChanged,
 * and each night taxonomy:tag-changes); its bank is built by request (the taxonomy command). The
 * search reads the tags only where taxonomy.in_search is on.
 */
final class TaxonomyServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(ProductTags::class, TagWords::class);
        $this->app->bind(ProductMeaning::class, Meaning::class);
        $this->app->bind(RelatedByTags::class, RelatedProducts::class);
        $this->app->bind(ShopRules::class, Rules::class);
    }

    protected function bootModule(): void
    {
        // What a shop that tags by itself changed: products within minutes of their sync, everything that changed
        // after a whole read of the store.
        Event::listen([CatalogUpdated::class, CatalogProductsChanged::class], TagWhatChanged::class);

        // And each night, after the night's read (02:30), the store's pages (02:38) and the scan: whatever the day
        // brought that no event told.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('taxonomy:tag-changes')
                ->dailyAt('05:10')
                ->timezone('Asia/Jerusalem')
                ->name('taxonomy:tag-changes')
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            TaxonomyCommand::class,
            TagChangesCommand::class,
            FixRangesCommand::class,
        ];
    }
}
