<?php

namespace App\Modules\Taxonomy;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Taxonomy\Console\TaxonomyCommand;

/**
 * A store's tag bank and its products' tags, for any kind of store (BuildTagBank, AssignTags,
 * MergeTagProposals). In its pilot it runs by request (taxonomy.tagging, the taxonomy command);
 * the search reads the tags only where taxonomy.in_search is on.
 */
final class TaxonomyServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        //
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
