<?php

namespace App\Modules\SearchSubscriptions\Support;

use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\Shopify\Contracts\KeepsShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;

/** What Search Subscriptions keeps about a shopper who asks the store for it: their searches, as kept. */
final class SubscriberData implements KeepsShopperData
{
    public function about(ShopperDataRequested $request): array
    {
        if (! Subscribers::validEmail($request->email)) {
            return [];
        }
        $rows = SearchSubscription::query()->where('email_hash', Subscribers::hash($request->email))->orderBy('created_at')->get()
            ->map(fn (SearchSubscription $s): array => [
                'search' => $s->query,
                'status' => $s->status,
                'consent' => $s->consent_text,
                'consented_at' => $s->consent_at->toIso8601String(),
                'confirmed_at' => $s->confirmed_at?->toIso8601String(),
                'ends_at' => $s->expires_at?->toIso8601String(),
                'stopped_at' => $s->stopped_at?->toIso8601String(),
            ])->all();

        return $rows === [] ? [] : ['Search subscriptions' => $rows];
    }
}
