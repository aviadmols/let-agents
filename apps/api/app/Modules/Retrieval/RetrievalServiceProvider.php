<?php

namespace App\Modules\Retrieval;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Retrieval\Candidates\BoughtTogether;
use App\Modules\Retrieval\Candidates\LookAlike;
use App\Modules\Retrieval\Candidates\MentionedTogether;
use App\Modules\Retrieval\Candidates\SimilarProducts;
use App\Modules\Retrieval\Console\RetrievalCommand;
use App\Modules\Retrieval\Contracts\RunsRetrieval;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Sources\ContentDocuments;
use App\Modules\Retrieval\Sources\ProductDocuments;
use App\Modules\Retrieval\Sources\PurchaseDocuments;
use App\Modules\Retrieval\Support\RetrievalRunner;
use App\Modules\Retrieval\Support\VectorSearch;
use Illuminate\Console\Scheduling\Schedule;

final class RetrievalServiceProvider extends ModuleServiceProvider
{
    /**
     * What the index reads. Another module adds a source by tagging its own DocumentSource
     * `retrieval.sources`; the index and the panel's map pick it up from the tag.
     */
    public const SOURCES = [ProductDocuments::class, ContentDocuments::class, PurchaseDocuments::class];

    /** Where the matcher looks for candidates. Same idea: tag a CandidateSource `retrieval.candidates`. */
    public const CANDIDATES = [BoughtTogether::class, SimilarProducts::class, LookAlike::class, MentionedTogether::class];

    protected function registerModule(): void
    {
        $this->app->bind(SemanticSearch::class, VectorSearch::class);
        $this->app->bind(RunsRetrieval::class, RetrievalRunner::class);
        $this->app->tag(self::SOURCES, 'retrieval.sources');
        $this->app->tag(self::CANDIDATES, 'retrieval.candidates');
    }

    protected function bootModule(): void
    {
        // After the catalogue sync (02:30) and before Enrichment's nightly reading (03:10), which
        // turns accepted matches into relations: the index first, then the matching that uses it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('retrieval nightly --all')
                ->dailyAt('02:45')
                ->timezone('Asia/Jerusalem')
                ->name('retrieval:nightly')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            RetrievalCommand::class,
        ];
    }
}
