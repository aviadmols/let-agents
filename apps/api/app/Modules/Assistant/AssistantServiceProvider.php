<?php

namespace App\Modules\Assistant;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Assistant\Actions\SuggestQuestions;
use App\Modules\Assistant\Console\ReviewAsksCommand;
use App\Modules\Assistant\Contracts\SuggestsQuestions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
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

        // In the morning, after the day's questions are in: the report on yesterday's search questions.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('assistant:review-asks --all')
                ->dailyAt('05:15')
                ->timezone('Asia/Jerusalem')
                ->name('assistant:review-asks')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            ReviewAsksCommand::class,
        ];
    }
}
