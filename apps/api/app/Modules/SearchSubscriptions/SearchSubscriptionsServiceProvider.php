<?php

namespace App\Modules\SearchSubscriptions;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\SearchSubscriptions\Actions\ForgetRemovedStores;
use App\Modules\SearchSubscriptions\Actions\RemindEnding;
use App\Modules\SearchSubscriptions\Actions\SendWeekly;
use App\Modules\SearchSubscriptions\Listeners\ForgetSubscriber;
use App\Modules\SearchSubscriptions\Support\SubscriberData;
use App\Modules\Shopify\Contracts\KeepsShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Search Subscriptions: a shopper subscribes to a search in the drawer and gets one email a week at most with its new,
 * in-stock products (docs: lets-search-subscriptions). Off unless search_subscriptions.enabled is on for the store.
 */
final class SearchSubscriptionsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // A shopper who asks the store what it keeps about them: their searches.
        $this->app->tag([SubscriberData::class], KeepsShopperData::TAG);
    }

    protected function bootModule(): void
    {
        // sign-ups per IP and store (the double opt-in is the other half against bots)
        RateLimiter::for('search-subscriptions', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('search_subscriptions.signups_per_minute'))
            ->by('search-subscriptions:'.$request->ip().'|'.$request->route('site')));
        RateLimiter::for('search-subscription-links', fn (Request $request): Limit => Limit::perMinute(60)->by('search-subscription-links:'.$request->ip()));

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // each store at its own day and hour, in its own time: looked at every hour
            $schedule->call(fn () => app(SendWeekly::class)->handle())->hourlyAt(2)->name('search-subscriptions:weekly')->withoutOverlapping(50);
            $schedule->call(fn () => app(RemindEnding::class)->handle())->dailyAt('09:15')->name('search-subscriptions:remind')->withoutOverlapping(60);
            $schedule->call(fn () => app(ForgetRemovedStores::class)->handle())->dailyAt('03:40')->name('search-subscriptions:forget-removed');
        });

        // A shopper's request to be erased: their address goes from every store.
        Event::listen(ShopperDataRequested::class, ForgetSubscriber::class);
    }
}
