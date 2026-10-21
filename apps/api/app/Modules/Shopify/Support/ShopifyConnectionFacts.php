<?php

namespace App\Modules\Shopify\Support;

use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\ConnectionFacts;
use App\Modules\Shopify\Http\Controllers\ShopifyAppController;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * A Shopify store on "Connection and plan": whether the app is installed, and its plan, billed every
 * 30 days on the merchant's Shopify invoice after the trial.
 */
final class ShopifyConnectionFacts implements ConnectionFacts
{
    public function facts(Shop $shop): array
    {
        $install = ShopifyInstall::query()->where('shop_id', $shop->id)->latest('installed_at')->first();

        if ($install === null) {
            return [];
        }

        $facts = ['app_installed' => $install->installed(), 'connected' => $install->installed()];

        // the merchant changes plan themselves, in Shopify's billing
        if ($install->installed() && Plan::query()->offered()->exists()) {
            $facts['plans_url'] = ShopifyAppController::plansLink($install->shop_domain);
        }

        if (! $install->subscribed()) {
            return $facts + ['plan_waiting' => $install->installed()];
        }

        $trial = $install->trial_ends_at !== null && $install->trial_ends_at->isFuture() ? $install->trial_ends_at : null;

        return $facts + [
            'plan' => $shop->currentPlan()?->billingName() ?? (string) Settings::get('shopify.plan_name'),
            'trial_ends' => $trial,
            'renews' => $install->subscription_status === ShopifyInstall::ACTIVE ? self::renews($trial ?? $install->subscribed_at) : null,
        ];
    }

    /** The next 30-day boundary after the period started: when Shopify bills again. */
    private static function renews(?CarbonInterface $from): ?CarbonInterface
    {
        if ($from === null) {
            return null;
        }

        $next = $from->copy();
        while ($next->lessThanOrEqualTo(now())) {
            $next = $next->addDays(30);
        }

        return $next;
    }
}
