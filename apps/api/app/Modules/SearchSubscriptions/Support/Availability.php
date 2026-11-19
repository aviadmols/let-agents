<?php

namespace App\Modules\SearchSubscriptions\Support;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Tenancy\Models\Shop;

/**
 * Whether a store has Search Subscriptions: only on the plans named in search_subscriptions.plans (Growth, Pro), and only
 * while search_subscriptions.enabled is on for it (off by default). The plan is read from the shop (shops.plan, the
 * code the billing wrote), never changed here. Every door goes through it: the drawer's offer (DrawerOffer), the
 * sign-up and its status (SubscribeController), the weekly email (SendWeekly), the reminder (RemindEnding) and the
 * store's settings page.
 */
final class Availability
{
    /** On for the store: the feature is on and its plan carries it. */
    public static function on(string $shopId): bool
    {
        return $shopId !== '' && Features::enabled('search_subscriptions.enabled', $shopId) && self::planAllows($shopId);
    }

    /**
     * The store's own settings page shows: its plan carries the feature, and it is offered to stores
     * (search_subscriptions.offered, off until the emails can go out) or already on for this one.
     */
    public static function offered(string $shopId): bool
    {
        return $shopId !== '' && self::planAllows($shopId)
            && (Features::enabled('search_subscriptions.offered', $shopId) || Features::enabled('search_subscriptions.enabled', $shopId));
    }

    /** The store's plan is one of search_subscriptions.plans (codes, comma separated). A store with no plan has none of them. */
    public static function planAllows(string $shopId): bool
    {
        $plan = strtolower((string) (Shop::query()->whereKey($shopId)->value('plan') ?? ''));

        return $plan !== '' && in_array($plan, self::plans(), true);
    }

    /** @return list<string> the plan codes that carry the feature */
    public static function plans(): array
    {
        $codes = preg_split('/[\s,]+/u', strtolower((string) Settings::get('search_subscriptions.plans'))) ?: [];

        return array_values(array_filter($codes, fn (string $code): bool => $code !== ''));
    }
}
