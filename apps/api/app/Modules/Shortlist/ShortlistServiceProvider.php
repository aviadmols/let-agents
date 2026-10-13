<?php

namespace App\Modules\Shortlist;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shortlist\Listeners\ForgetShopper;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class ShortlistServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        //
    }

    protected function bootModule(): void
    {
        // One visitor's own clicks on hearts and compare boxes, per site.
        RateLimiter::for('shortlist', fn (Request $request): Limit => Limit::perMinute((int) Settings::get('shortlist.requests_per_minute'))
            ->by('shortlist:'.$request->ip().'|'.($request->route('site') ?? 'proxy')));

        // A Shopify customer's request to be forgotten takes the wishlist in their account.
        Event::listen(ShopperDataRequested::class, ForgetShopper::class);
    }
}
