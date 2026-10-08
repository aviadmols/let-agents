<?php

namespace App\Modules\Search;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Search\Console\ResumePictureScansCommand;
use App\Modules\Search\Console\SearchCommand;
use App\Modules\Search\Contracts\PageTags;
use App\Modules\Search\Listeners\RebuildSearchIndex;
use App\Modules\Search\Support\StoredPageTags;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class SearchServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        $this->app->bind(PageTags::class, StoredPageTags::class);
        Event::listen(CatalogUpdated::class, RebuildSearchIndex::class);

        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('search.requests_per_minute'))
            ->by('search:'.$request->ip().'|'.$request->route('site')));

        // Off while search.photo_limits is off: the spending cap still holds every model call.
        RateLimiter::for('search-photo', fn (Request $request): Limit => Features::enabled('search.photo_limits')
            ? Limit::perMinute((int) Settings::get('search.photo_requests_per_minute'))->by('search-photo:'.$request->ip().'|'.$request->route('site'))
            : Limit::none());

        // After the catalogue sync (02:30), so the box searches what the store publishes today.
        // The counts are kept for search.keep_days and then dropped.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('search index --all')
                ->dailyAt('02:40')
                ->timezone('Asia/Jerusalem')
                ->name('search:index')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            // A picture scan cut off by a deploy or a crash goes on, however many pictures are left.
            $schedule->command('search:resume-pictures')
                ->everyTenMinutes()
                ->name('search:resume-pictures')
                ->withoutOverlapping()
                ->onOneServer();

            // After the index (02:40) and the vectors (02:45): a tag is kept only when the search fills it.
            $schedule->command('search tags --all')
                ->dailyAt('03:30')
                ->timezone('Asia/Jerusalem')
                ->name('search:tags')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            // After the vectors (02:45) and before the daily review (04:30), which sees what was not resolved.
            $schedule->command('search resolve --all')
                ->dailyAt('04:00')
                ->timezone('Asia/Jerusalem')
                ->name('search:resolve')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();

            $schedule->command('search prune')
                ->dailyAt('04:20')
                ->timezone('Asia/Jerusalem')
                ->name('search:prune')
                ->onOneServer();
        });
    }

    protected function moduleCommands(): array
    {
        return [
            ResumePictureScansCommand::class,
            SearchCommand::class,
        ];
    }
}
