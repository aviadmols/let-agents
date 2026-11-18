<?php

namespace App\Modules\SearchSubscriptions\Support;

use App\Core\Facades\Settings;
use App\Modules\Search\Contracts\DrawerConfig;
use App\Modules\Tenancy\Models\Shop;

/**
 * The drawer's "subscribe" key: where the offer shows ("end": at the end of a search's results and in the no-exact-match
 * state; "no_match": only there) and the store's name for the consent line. Only while the store has the feature
 * (Availability: on, and its plan carries it); otherwise no key, and the drawer shows nothing of it.
 */
final class DrawerOffer implements DrawerConfig
{
    public function forDrawer(string $shopId): array
    {
        if (! Availability::on($shopId)) {
            return [];
        }

        return ['subscribe' => [
            'at' => Settings::get('search_subscriptions.placement', $shopId) === 'no_match' ? 'no_match' : 'end',
            'store' => trim((string) (Shop::query()->whereKey($shopId)->value('name') ?? '')),
        ]];
    }
}
