<?php

namespace App\Modules\Ai\Contracts;

/**
 * The spending caps on the model keys saved in the panel. Code that calls a model with those keys
 * asks first, with its estimate, and records the real cost on its run afterwards
 * (RunContext::usage), which is what later checks add up.
 *
 * Two caps, both per calendar month (UTC): one for the whole platform, and one for each shop
 * (setting ai.shop_monthly_cap_usd, $30 by default), checked when the call is made inside a shop.
 * A call made for a shopper on the site ($forShopper) passes the shop's cap unless the operator
 * set ai.shop_cap_stops_shoppers for that shop: the site keeps answering while background work
 * waits for next month.
 *
 * Model work done outside the platform and uploaded as answer files costs nothing here.
 */
interface SpendGuard
{
    /** @throws SpendCapReached when this month's cost plus the estimate would pass a cap */
    public function assertCanSpend(float $estimatedUsd, bool $forShopper = false): void;

    /** What runs recorded this calendar month, in USD, for every shop. */
    public function spentThisMonth(): float;

    /** The platform's monthly cap, in USD (setting ai.monthly_spend_cap_usd). */
    public function cap(): float;

    /** What runs recorded for one shop this calendar month, in USD. */
    public function spentThisMonthFor(string $shopId): float;

    /** One shop's monthly allowance, in USD (setting ai.shop_monthly_cap_usd). */
    public function capFor(string $shopId): float;
}
