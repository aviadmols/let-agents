<?php

namespace App\Modules\Tenancy;

use App\Core\Facades\Settings;
use App\Core\Modules\ModuleServiceProvider;
use App\Modules\Tenancy\Actions\CreateShop;
use App\Modules\Tenancy\Contracts\CreatesShops;
use App\Modules\Tenancy\Http\Middleware\AuthenticateShopKey;
use App\Modules\Tenancy\Models\ShopApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class TenancyServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->bind(CreatesShops::class, CreateShop::class);
    }

    protected function bootModule(): void
    {
        $this->app['router']->aliasMiddleware('shop.key', AuthenticateShopKey::class);

        // Per key, with the ceiling taken from the shop's own setting.
        RateLimiter::for('shop-api', function (Request $request): Limit {
            $key = $request->attributes->get('shop_api_key');

            if (! $key instanceof ShopApiKey) {
                return Limit::perMinute(60)->by('ip:'.$request->ip());
            }

            return Limit::perMinute((int) Settings::get('tenancy.api_requests_per_minute', $key->shop_id))
                ->by('key:'.$key->id)
                ->response(fn () => response()->json(['error' => 'rate_limited'], 429));
        });
    }
}
