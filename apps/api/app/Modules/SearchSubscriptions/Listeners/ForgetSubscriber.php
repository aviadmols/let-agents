<?php

namespace App\Modules\SearchSubscriptions\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionProduct;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use App\Modules\Shopify\Events\ShopperDataRequested;

/**
 * A shopper asked to be erased: their address goes from every store's subscriptions, not only the one that asked (the
 * hash is the same everywhere), with what was sent to it.
 */
final class ForgetSubscriber
{
    public function handle(ShopperDataRequested $event): void
    {
        if ($event->kind !== 'redact' || ! Subscribers::validEmail($event->email)) {
            return;
        }
        $hash = Subscribers::hash($event->email);
        app(TenantContext::class)->runUnscoped(function () use ($hash): void {
            SearchSubscriptionProduct::query()->where('email_hash', $hash)->delete();
            SearchSubscriptionSend::query()->where('email_hash', $hash)->delete();
            SearchSubscription::query()->where('email_hash', $hash)->delete();
        });
    }
}
