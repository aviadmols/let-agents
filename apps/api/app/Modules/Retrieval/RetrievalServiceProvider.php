<?php

namespace App\Modules\Retrieval;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\ScanProgress;
use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Catalog\Events\CatalogLinksMoved;
use App\Modules\Catalog\Events\CatalogProductsChanged;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Retrieval\Actions\CaptionImages;
use App\Modules\Retrieval\Candidates\BoughtTogether;
use App\Modules\Retrieval\Candidates\LookAlike;
use App\Modules\Retrieval\Candidates\MentionedTogether;
use App\Modules\Retrieval\Candidates\SimilarProducts;
use App\Modules\Retrieval\Console\RetrievalCommand;
use App\Modules\Retrieval\Console\SwitchEmbedderCommand;
use App\Modules\Retrieval\Contracts\CaptionsPictures;
use App\Modules\Retrieval\Contracts\ChunkExperiments;
use App\Modules\Retrieval\Contracts\EmbedderTrials;
use App\Modules\Retrieval\Contracts\Passages;
use App\Modules\Retrieval\Contracts\PictureContent;
use App\Modules\Retrieval\Contracts\RefreshesVectors;
use App\Modules\Retrieval\Contracts\RunsRetrieval;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Contracts\TopicQuestions;
use App\Modules\Retrieval\Listeners\MovePictureLinks;
use App\Modules\Retrieval\Listeners\RefreshVectors;
use App\Modules\Retrieval\Sources\ContentDocuments;
use App\Modules\Retrieval\Sources\ProductDocuments;
use App\Modules\Retrieval\Sources\PurchaseDocuments;
use App\Modules\Retrieval\Support\InMemoryChunkExperiments;
use App\Modules\Retrieval\Support\InMemoryEmbedderTrials;
use App\Modules\Retrieval\Support\PassageSearch;
use App\Modules\Retrieval\Support\QueuedVectorRefresh;
use App\Modules\Retrieval\Support\RetrievalRunner;
use App\Modules\Retrieval\Support\RetrievalScanProgress;
use App\Modules\Retrieval\Support\RetrievalShopFacts;
use App\Modules\Retrieval\Support\TopicAsks;
use App\Modules\Retrieval\Support\VectorSearch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

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
        $this->app->bind(Passages::class, PassageSearch::class);
        $this->app->bind(TopicQuestions::class, TopicAsks::class);
        // One per process: the pieces it embeds are kept for the next question of the same experiment.
        $this->app->singleton(ChunkExperiments::class, InMemoryChunkExperiments::class);
        $this->app->bind(EmbedderTrials::class, InMemoryEmbedderTrials::class);
        $this->app->bind(RunsRetrieval::class, RetrievalRunner::class);
        $this->app->bind(CaptionsPictures::class, CaptionImages::class);
        $this->app->bind(PictureContent::class, VectorSearch::class);
        $this->app->bind(RefreshesVectors::class, QueuedVectorRefresh::class);
        $this->app->tag(self::SOURCES, 'retrieval.sources');
        $this->app->tag(self::CANDIDATES, 'retrieval.candidates');
        // What this module counted, for the first-run screens.
        $this->app->tag([RetrievalShopFacts::class], ShopFacts::TAG);
        // How far its steps of the scan are, while they run.
        $this->app->tag([RetrievalScanProgress::class], ScanProgress::TAG);
    }

    protected function bootModule(): void
    {
        // What changed today is searchable by meaning today: a sync queues one refresh of the vectors.
        Event::listen([CatalogUpdated::class, CatalogProductsChanged::class], RefreshVectors::class);
        // The store moved: its pictures' vectors and captions are kept under their new links, never read again.
        Event::listen(CatalogLinksMoved::class, MovePictureLinks::class);

        // After the catalogue sync (02:30) and before Enrichment's nightly reading (03:10), which
        // turns accepted matches into relations: the index first, then the matching that uses it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('retrieval nightly --all')
                ->dailyAt('02:45')
                ->timezone('Asia/Jerusalem')
                ->name('retrieval:nightly')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            RetrievalCommand::class,
            SwitchEmbedderCommand::class,
        ];
    }
}
