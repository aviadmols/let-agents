<?php

namespace App\Modules\Recovery\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Recovery\Models\RecoveryCart;
use App\Modules\Shopify\Events\ShopperDataRequested;

/**
 * A shopper asked the store to erase what is kept about them: their abandoned carts (the only
 * place an email is kept here) go. A request to report is answered by the store from Shopify;
 * nothing beyond those carts is kept to report.
 */
final class ForgetShopper
{
    public function handle(ShopperDataRequested $event): void
    {
        if ($event->kind !== 'redact' || $event->email === '') {
            return;
        }

        app(TenantContext::class)->run($event->shopId, fn () => RecoveryCart::query()
            ->where('email_hash', hash('sha256', $event->shopId.'|email|'.$event->email))
            ->delete());
    }
}
