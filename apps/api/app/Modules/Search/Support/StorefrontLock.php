<?php

namespace App\Modules\Search\Support;

use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Whether a Shopify store still asks for its password at the door (a new or development store): "See it as a shopper"
 * landed there, the moment a merchant wants to see the result (the App Store review's run, 17.11). Read from the store's
 * own front page, which sends a locked store to /password; kept ten minutes. Never asked in tests.
 */
class StorefrontLock
{
    private const SECONDS = 600;

    public function locked(Shop $shop): bool
    {
        if ($shop->platform !== ShopPlatform::Shopify || ! is_string($shop->domain) || $shop->domain === '' || app()->runningUnitTests()) {
            return false;
        }

        return (bool) Cache::remember('storefront-locked:'.$shop->id, self::SECONDS, function () use ($shop): bool {
            try {
                $response = Http::timeout(4)->withoutRedirecting()->get('https://'.$shop->domain.'/');

                return $response->redirect() && str_contains((string) $response->header('Location'), '/password');
            } catch (Throwable) {
                return false;
            }
        });
    }
}
