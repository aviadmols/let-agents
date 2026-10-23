<?php

namespace App\Modules\Shopify\Support;

use App\Modules\Admin\Contracts\CancelsSubscription;
use App\Modules\Shopify\Actions\ManageSubscription;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * A Shopify store's plan, billed on the merchant's Shopify invoice: cancelled in Shopify when the owner ends it, the
 * shop working on to the end of the period already paid. A store charged nothing (free) has no such period.
 */
final class ShopifySubscription implements CancelsSubscription
{
    public function __construct(private readonly ManageSubscription $billing) {}

    public function bills(Shop $shop): bool
    {
        return $this->install($shop)?->subscribed() ?? false;
    }

    public function keepsPaidPeriod(Shop $shop): bool
    {
        $install = $this->install($shop);

        return $install !== null && $install->installed() && $install->subscription_status === ShopifyInstall::ACTIVE;
    }

    public function cancel(Shop $shop): ?CarbonInterface
    {
        $install = $this->install($shop);

        return $install !== null && $install->installed() ? $this->billing->cancel($install) : null;
    }

    private function install(Shop $shop): ?ShopifyInstall
    {
        return ShopifyInstall::query()->where('shop_id', $shop->id)->latest('installed_at')->first();
    }
}
