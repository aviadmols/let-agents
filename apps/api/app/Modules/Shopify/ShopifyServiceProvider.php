<?php

namespace App\Modules\Shopify;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Shopify\Feed\ShopifyStoreFeed;
use App\Modules\Shopify\Http\Middleware\FrameInsideShopify;
use App\Modules\Shopify\Support\EmbeddedApp;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
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

        // Inside the Shopify admin: a shop's panel may be framed by its own admin alone, and
        // carries App Bridge, the admin's bridge to the frame.
        $this->app->make(Kernel::class)->pushMiddleware(FrameInsideShopify::class);
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_START, fn (): string => EmbeddedApp::head());
    }
}
