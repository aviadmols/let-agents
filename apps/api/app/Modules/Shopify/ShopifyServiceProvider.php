<?php

namespace App\Modules\Shopify;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Shopify\Feed\ShopifyStoreFeed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class ShopifyServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->tag([ShopifyStoreFeed::class], 'connections.store_feeds');
    }

    protected function bootModule(): void
    {
        // Shopify's own calls and merchants opening the app: generous, only against a flood.
        RateLimiter::for('shopify', fn (Request $request): Limit => Limit::perMinute(120)->by('shopify:'.$request->ip()));
    }
}
