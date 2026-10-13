<?php

namespace App\Modules\Shortlist\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shortlist\Models\ShortlistWishlist;

/**
 * A Shopify customer asked the store to erase what is kept about them: the wishlist kept in their
 * account goes. A guest's list is kept by a browser token, with nothing that names anyone.
 */
final class ForgetShopper
{
    public function handle(ShopperDataRequested $event): void
    {
        if ($event->kind !== 'redact' || preg_match('/^[0-9]{1,20}$/', $event->customerId) !== 1) {
            return;
        }

        app(TenantContext::class)->run($event->shopId, fn () => ShortlistWishlist::query()
            ->where('customer_ref', 'shopify:'.$event->customerId)
            ->delete());
    }
}
