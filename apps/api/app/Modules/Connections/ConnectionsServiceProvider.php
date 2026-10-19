<?php

namespace App\Modules\Connections;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Admin\Contracts\ConnectionFacts;
use App\Modules\Admin\Contracts\StorePlugin;
use App\Modules\Connections\Console\BundlePluginCommand;
use App\Modules\Connections\Contracts\StoreBlog;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Http\Middleware\FrameInsideWordPress;
use App\Modules\Connections\Support\FollowShopDomain;
use App\Modules\Connections\Support\MerchantStorePlugin;
use App\Modules\Connections\Support\PlatformBlogs;
use App\Modules\Connections\Support\PlatformFeeds;
use App\Modules\Connections\Support\PluginBlog;
use App\Modules\Connections\Support\PluginStoreFeed;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class ConnectionsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        // Each platform's reader is tagged; the store feed picks the one for a connection's platform.
        $this->app->tag([PluginStoreFeed::class], 'connections.store_feeds');
        $this->app->bind(StoreFeed::class, fn ($app) => new PlatformFeeds($app->tagged('connections.store_feeds')));
        // And its blog: where a post goes as a draft.
        $this->app->tag([PluginBlog::class], 'connections.store_blogs');
        $this->app->bind(StoreBlog::class, fn ($app) => new PlatformBlogs($app->tagged('connections.store_blogs')));
        // The plugin from the shop's own panel: its zip, a connection token, what the connection says.
        $this->app->bind(StorePlugin::class, MerchantStorePlugin::class);
        $this->app->tag([MerchantStorePlugin::class], ConnectionFacts::TAG);
    }

    protected function bootModule(): void
    {
        // A shop that moved to a new domain is read at the new address from then on.
        Shop::updated(fn (Shop $shop) => FollowShopDomain::handle($shop));

        // The panel inside wp-admin: a sign-in address now and then per store, and the frame that opens it.
        RateLimiter::for('plugin-panel', fn (Request $request): Limit => Limit::perMinute(30)->by('plugin-panel:'.$request->ip()));
        // A WooCommerce shop's panel may be framed by the store's own addresses alone.
        $this->app->make(Kernel::class)->pushMiddleware(FrameInsideWordPress::class);
    }

    protected function moduleCommands(): array
    {
        return [
            BundlePluginCommand::class,
        ];
    }
}
