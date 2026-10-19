<?php

namespace App\Modules\Assistant;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\FirstSteps;
use App\Modules\Admin\Contracts\HomeCards;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\ReadsContentAgain;
use App\Modules\Admin\Contracts\ShopReport;
use App\Modules\Admin\Contracts\TimelineSource;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Actions\SuggestQuestions;
use App\Modules\Assistant\Console\AnswerReportCommand;
use App\Modules\Assistant\Console\CheckerReportCommand;
use App\Modules\Assistant\Console\ContextEvalCommand;
use App\Modules\Assistant\Console\KnowledgeCommand;
use App\Modules\Assistant\Console\QualityBaselineCommand;
use App\Modules\Assistant\Console\QualityCasesCommand;
use App\Modules\Assistant\Console\ReadPostsCommand;
use App\Modules\Assistant\Console\RetrievalBenchCommand;
use App\Modules\Assistant\Console\ReviewAsksCommand;
use App\Modules\Assistant\Console\WriterBenchCommand;
use App\Modules\Assistant\Contracts\SuggestsQuestions;
use App\Modules\Assistant\Listeners\ReadShopKnowledge;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Assistant\Support\AssistantHome;
use App\Modules\Assistant\Support\AssistantReport;
use App\Modules\Assistant\Support\AssistantTimeline;
use App\Modules\Assistant\Support\ContentReadAgain;
use App\Modules\Catalog\Events\CatalogUpdated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class AssistantServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(ReadsContentAgain::class, ContentReadAgain::class);
        $this->app->tag([AssistantReport::class], ShopReport::REPORT_TAG);
        $this->app->tag([AssistantTimeline::class], TimelineSource::TAG);
        // What this module shows on the shop's home and notices.
        $this->app->tag([AssistantHome::class], HomeSource::TAG);
        $this->app->tag([AssistantHome::class], HomeCards::CARDS_TAG);
        $this->app->tag([AssistantHome::class], FirstSteps::STEPS_TAG);
        $this->app->bind(SuggestsQuestions::class, SuggestQuestions::class);
        //
    }

    protected function bootModule(): void
    {
        RateLimiter::for('assistant-ask', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('assistant.asks_per_minute'))
            ->by('assistant-ask:'.$request->ip().'|'.$request->route('site')));

        // Every sync, the first included: what the store's pages repeat is read again.
        Event::listen(CatalogUpdated::class, ReadShopKnowledge::class);

        // A team answer to a store question, written, changed, hidden or removed: what the assistant
        // knows about the store follows at once.
        $team = function (AssistantAnswer $answer): void {
            if ($answer->product_id !== null || $answer->content_id !== null) {
                return;
            }

            if ($answer->source !== 'team' && $answer->getOriginal('source') !== 'team') {
                return;
            }

            if (Features::enabled('assistant.knowledge', $answer->shop_id)) {
                app(TenantContext::class)->run($answer->shop_id, fn () => app(BuildShopKnowledge::class)->team());
            }
        };
        AssistantAnswer::saved($team);
        AssistantAnswer::deleted($team);

        // In the morning, after the day's questions are in: the report on yesterday's search questions.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('assistant:review-asks --all')
                ->dailyAt('05:15')
                ->timezone('Asia/Jerusalem')
                ->name('assistant:review-asks')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            // At night: what the new or changed posts say about the store.
            $schedule->command('assistant:read-posts --all')
                ->dailyAt('03:40')
                ->timezone('Asia/Jerusalem')
                ->name('assistant:read-posts')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            ReviewAsksCommand::class,
            KnowledgeCommand::class,
            ReadPostsCommand::class,
            CheckerReportCommand::class,
            AnswerReportCommand::class,
            ContextEvalCommand::class,
            WriterBenchCommand::class,
            RetrievalBenchCommand::class,
            QualityCasesCommand::class,
            QualityBaselineCommand::class,
        ];
    }
}
