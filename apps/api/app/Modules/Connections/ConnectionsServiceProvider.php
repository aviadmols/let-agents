<?php

namespace App\Modules\Connections;

use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Connections\Console\BundlePluginCommand;
use App\Modules\Connections\Contracts\StoreFeed;
use App\Modules\Connections\Support\FollowShopDomain;
use App\Modules\Connections\Support\PluginStoreFeed;
use App\Modules\Tenancy\Models\Shop;

final class ConnectionsServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(StoreFeed::class, PluginStoreFeed::class);
    }

    protected function bootModule(): void
    {
        // A shop that moved to a new domain is read at the new address from then on.
        Shop::updated(fn (Shop $shop) => FollowShopDomain::handle($shop));
    }

    protected function moduleCommands(): array
    {
        return [
            BundlePluginCommand::class,
        ];
    }
}
