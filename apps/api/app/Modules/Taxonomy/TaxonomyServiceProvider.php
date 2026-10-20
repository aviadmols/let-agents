<?php

namespace App\Modules\Taxonomy;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Enrichment\Contracts\ProductMeaning;
use App\Modules\Search\Contracts\ProductTags;
use App\Modules\Taxonomy\Console\TaxonomyCommand;
use App\Modules\Taxonomy\Support\Meaning;
use App\Modules\Taxonomy\Support\TagWords;

/**
 * A store's tag bank and its products' tags, for any kind of store (BuildTagBank, AssignTags,
 * MergeTagProposals). In its pilot it runs by request (taxonomy.tagging, the taxonomy command);
 * the search reads the tags only where taxonomy.in_search is on.
 */
final class TaxonomyServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(ProductTags::class, TagWords::class);
        $this->app->bind(ProductMeaning::class, Meaning::class);
    }

    protected function bootModule(): void
    {
        //
    }

    protected function moduleCommands(): array
    {
        return [
            TaxonomyCommand::class,
        ];
    }
}
