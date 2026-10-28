<?php

namespace App\Modules\Demo;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Connections\Contracts\ContentSiteFeed;
use App\Modules\Demo\Console\SweepDemosCommand;
use App\Modules\Demo\Support\PublicStoreFeed;
use App\Modules\Demo\Support\WordPressContentFeed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A store someone asked to see Lets on, built from what it publishes and shown on our own page (see DemoController).
 * Its catalogue is read by PublicStoreFeed, the Connections registry's reader for platform "demo".
 */
final class DemoServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->tag([PublicStoreFeed::class], 'connections.store_feeds');
        // the one reader of a content site (WordPress with no WooCommerce): a demo's and a plugin-connected site's alike
        $this->app->bind(ContentSiteFeed::class, WordPressContentFeed::class);
    }

    protected function bootModule(): void
    {
        // a few demos an hour from one address; the code a few times a minute; the pages as a person browses them
        RateLimiter::for('demo-start', fn (Request $request): Limit => Limit::perHour(5)->by('demo-start:'.$request->ip()));
        RateLimiter::for('demo-code', fn (Request $request): Limit => Limit::perMinute(6)->by('demo-code:'.$request->ip()));
        RateLimiter::for('demo-page', fn (Request $request): Limit => Limit::perMinute(120)->by('demo-page:'.$request->ip()));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('demo:sweep')
                ->everyFiveMinutes()
                ->name('demo:sweep')
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }

    protected function moduleCommands(): array
    {
        return [SweepDemosCommand::class];
    }
}
