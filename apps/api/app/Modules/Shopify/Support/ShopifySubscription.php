<?php

namespace App\Modules\Shopify\Support;

use App\Modules\Admin\Contracts\CancelsSubscription;
use App\Modules\Shopify\Actions\ManageSubscription;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Models\Shop;

/** A Shopify store's plan, billed on the merchant's Shopify invoice: ended in Shopify when the owner ends it. */
final class ShopifySubscription implements CancelsSubscription
{
    public function __construct(private readonly ManageSubscription $billing) {}

    public function bills(Shop $shop): bool
    {
        return $this->install($shop)?->subscribed() ?? false;
    }

    public function cancel(Shop $shop): void
    {
        $install = $this->install($shop);

        if ($install !== null && $install->installed()) {
            $this->billing->cancel($install);
        }
    }

    private function install(Shop $shop): ?ShopifyInstall
    {
        return ShopifyInstall::query()->where('shop_id', $shop->id)->latest('installed_at')->first();
    }
}
