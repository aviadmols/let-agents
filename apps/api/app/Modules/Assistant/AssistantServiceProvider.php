<?php

namespace App\Modules\Assistant;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\BuildShopKnowledge;
use App\Modules\Assistant\Actions\SuggestQuestions;
use App\Modules\Assistant\Console\AnswerReportCommand;
use App\Modules\Assistant\Console\CheckerReportCommand;
use App\Modules\Assistant\Console\ContextEvalCommand;
use App\Modules\Assistant\Console\KnowledgeCommand;
use App\Modules\Assistant\Console\RetrievalBenchCommand;
use App\Modules\Assistant\Console\ReviewAsksCommand;
use App\Modules\Assistant\Console\WriterBenchCommand;
use App\Modules\Assistant\Contracts\SuggestsQuestions;
use App\Modules\Assistant\Listeners\ReadShopKnowledge;
use App\Modules\Assistant\Models\AssistantAnswer;
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
        });
    }

    protected function moduleCommands(): array
    {
        return [
            ReviewAsksCommand::class,
            KnowledgeCommand::class,
            CheckerReportCommand::class,
            AnswerReportCommand::class,
            ContextEvalCommand::class,
            WriterBenchCommand::class,
            RetrievalBenchCommand::class,
        ];
    }
}
