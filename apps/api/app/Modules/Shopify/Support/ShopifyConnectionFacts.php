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
    /** The app embed's block file (extensions/shopify/extensions/let-agents-search/blocks/app-embed.liquid). */
    public const EMBED = 'app-embed';

    public function facts(Shop $shop): array
    {
        $install = ShopifyInstall::query()->where('shop_id', $shop->id)->latest('installed_at')->first();

        if ($install === null) {
            return [];
        }

        $facts = ['app_installed' => $install->installed(), 'connected' => $install->installed()];

        // the theme editor with the app embed switched on, waiting for the merchant to save (Shopify's deep link)
        if ($install->installed()) {
            $facts['embed_url'] = 'https://'.$install->shop_domain.'/admin/themes/current/editor?context=apps&activateAppId='
                .rawurlencode(ShopifyApps::key($install->client_id)).'/'.self::EMBED;
        }

        // the merchant changes plan themselves, in Shopify's billing
        if ($install->installed() && Plan::query()->offered()->exists()) {
            $facts['plans_url'] = ShopifyAppController::plansLink($install->shop_domain);
            // each plan straight to Shopify's approval: an upgrade holds at once, a downgrade from the next period
            $facts['plan_urls'] = Plan::query()->offered()->pluck('code')->mapWithKeys(fn (string $code): array => [$code => ShopifyAppController::planLink($install->shop_domain, $code)])->all();
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
