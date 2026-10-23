<?php

namespace App\Modules\Shopify;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\CancelsSubscription;
use App\Modules\Admin\Contracts\ConnectionFacts;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\PanelHost;
use App\Modules\Shopify\Console\ConnectPixelCommand;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shopify\Feed\ShopifyBlog;
use App\Modules\Shopify\Feed\ShopifyStoreFeed;
use App\Modules\Shopify\Http\Middleware\CheckShopifySessionToken;
use App\Modules\Shopify\Http\Middleware\FrameInsideShopify;
use App\Modules\Shopify\Listeners\AnswerShopperData;
use App\Modules\Shopify\Support\EmbeddedApp;
use App\Modules\Shopify\Support\ShopifyConnectionFacts;
use App\Modules\Shopify\Support\ShopifyPanelHost;
use App\Modules\Shopify\Support\ShopifySubscription;
use App\Modules\Shopify\Support\ShopperDataHome;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class ShopifyServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->tag([ShopifyStoreFeed::class], 'connections.store_feeds');
        $this->app->tag([ShopifyBlog::class], 'connections.store_blogs');
        // The app and its plan on the shop's "Connection and plan".
        $this->app->tag([ShopifyConnectionFacts::class], ConnectionFacts::TAG);
        // The plan, ended in Shopify when the owner ends it or deletes the shop.
        $this->app->tag([ShopifySubscription::class], CancelsSubscription::TAG);
        // A shopper who asked for what the store keeps about them: the notice, until an owner downloads it.
        $this->app->tag([ShopperDataHome::class], HomeSource::TAG);
        // Inside the Shopify admin, Shopify signed the merchant in: the panel offers no sign-out and no password.
        $this->app->tag([ShopifyPanelHost::class], PanelHost::TAG);
    }

    protected function bootModule(): void
    {
        // Shopify's own calls and merchants opening the app: generous, only against a flood.
        RateLimiter::for('shopify', fn (Request $request): Limit => Limit::perMinute(120)->by('shopify:'.$request->ip()));

        // Inside the Shopify admin: a shop's panel may be framed by its own admin alone, and
        // carries App Bridge, the admin's bridge to the frame.
        $this->app->make(Kernel::class)->pushMiddleware(FrameInsideShopify::class);
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_START, fn (): string => EmbeddedApp::head());
        // the panel's requests from inside the admin carry its session token; where one comes, it is checked
        // (after the session starts, so it is checked against the person signed in)
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', CheckShopifySessionToken::class);

        // A shopper's privacy request: the report for the owners, or the reports made for them erased.
        Event::listen(ShopperDataRequested::class, AnswerShopperData::class);
    }

    protected function moduleCommands(): array
    {
        return [
            ConnectPixelCommand::class,
        ];
    }
}
