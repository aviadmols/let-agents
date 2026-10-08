<?php

namespace App\Modules\Shopify\Http\Controllers;

use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Actions\InstallStore;
use App\Modules\Shopify\Actions\ManageSubscription;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApps;
use App\Modules\Shopify\Support\ShopifySignatures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The app as a merchant meets it in Shopify:
 *
 *   GET /shopify/install          from the App Store or an install link: off to Shopify to agree
 *   GET /shopify/callback         Shopify answers: the store is installed and becomes a shop
 *   GET /shopify/app              the app opened from the Shopify admin: the shop's own panel
 *   GET /shopify/billing/return   after the merchant approved (or not) the monthly plan
 *
 * Every request Shopify sends is signed; a link older than a few minutes is refused, so a copied
 * link never logs anyone in. The signature also says which of our apps (ShopifyApps) the store is
 * using, and its tokens are asked for with that app's credentials.
 */
final class ShopifyAppController
{
    /** A signed link from Shopify is good for this long. */
    private const FRESH_SECONDS = 600;

    private const STATE_SECONDS = 600;

    private const MERCHANT_OVERVIEW_ROUTE = 'filament.merchant.pages.overview';

    public function install(Request $request): RedirectResponse|Response
    {
        $shop = ShopifySignatures::shopDomain($request->query('shop'));
        $app = ShopifyApps::key($request->query('app'));

        if ($shop === null || $app === '') {
            return response(__('shopify::app.bad_request'), 400);
        }

        $state = Str::random(40);
        Cache::put('shopify:state:'.$state, $shop, self::STATE_SECONDS);

        return redirect()->away('https://'.$shop.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => $app,
            'scope' => config('services.shopify.scopes'),
            'redirect_uri' => url('/shopify/callback'),
            'state' => $state,
        ]));
    }

    public function callback(Request $request, InstallStore $installer, ManageSubscription $billing): RedirectResponse|Response
    {
        $query = $request->query();
        $shop = ShopifySignatures::shopDomain($request->query('shop'));
        $expected = Cache::pull('shopify:state:'.$request->query('state'));
        $app = ShopifySignatures::queryApp($query);

        if ($shop === null || ! $this->signedAndFresh($query) || $expected !== $shop || blank($request->query('code'))) {
            return response(__('shopify::app.bad_request'), 403);
        }

        try {
            $token = Http::acceptJson()->timeout(30)->post('https://'.$shop.'/admin/oauth/access_token', [
                'client_id' => $app,
                'client_secret' => ShopifyApps::secret($app),
                'code' => (string) $request->query('code'),
                // An offline token that expires and is renewed with a refresh token.
                'expiring' => 1,
            ]);
        } catch (Throwable) {
            return response(__('shopify::app.unreachable'), 502);
        }

        if (! $token->successful() || ! is_string($token->json('access_token'))) {
            return response(__('shopify::app.unreachable'), 502);
        }

        $install = $installer->handle($shop, (array) $token->json());
        $install->forceFill(['client_id' => $app])->save();

        return $this->onward($install, $billing);
    }

    public function open(Request $request, ManageSubscription $billing): RedirectResponse|Response
    {
        $shop = ShopifySignatures::shopDomain($request->query('shop'));

        if ($shop === null || ! $this->signedAndFresh($request->query())) {
            return response(__('shopify::app.bad_request'), 403);
        }

        $install = ShopifyInstall::query()->where('shop_domain', $shop)->first();

        if ($install === null || ! $install->installed()) {
            // Shopify installed the app already; the store still needs our token, from the same app.
            return redirect()->to('/shopify/install?'.http_build_query(['shop' => $shop, 'app' => ShopifySignatures::queryApp($request->query())]));
        }

        return $this->onward($install, $billing);
    }

    public function billingReturn(Request $request, ManageSubscription $billing): RedirectResponse|Response
    {
        $shop = ShopifySignatures::shopDomain($request->query('shop'));
        $install = $shop === null ? null : ShopifyInstall::query()->where('shop_domain', $shop)->first();

        if ($install === null || ! $install->installed()) {
            return response(__('shopify::app.bad_request'), 404);
        }

        // Whatever the link says, the subscription is read from Shopify itself.
        if (! in_array($billing->refresh($install), [ShopifyInstall::ACTIVE, ShopifyInstall::FREE], true)) {
            return response(view('shopify::declined', ['shop' => $shop]), 402);
        }

        return $this->intoPanel($install);
    }

    /** A subscribed store goes to its panel; one without a subscription approves the plan first. */
    private function onward(ShopifyInstall $install, ManageSubscription $billing): RedirectResponse
    {
        if (! in_array($billing->refresh($install), [ShopifyInstall::ACTIVE, ShopifyInstall::FREE], true)) {
            return redirect()->away($billing->start($install, url('/shopify/billing/return?'.http_build_query(['shop' => $install->shop_domain]))));
        }

        return $this->intoPanel($install);
    }

    /** The store's owner, signed in by Shopify's word, in the shop's own panel. */
    private function intoPanel(ShopifyInstall $install): RedirectResponse
    {
        $owner = User::query()->whereHas('shops', fn ($q) => $q->where('shops.id', $install->shop_id)->where('shop_user.role', 'owner'))->first();

        if ($owner !== null && ! $owner->is_operator) {
            Auth::login($owner);
            request()->session()->regenerate();
        }

        return redirect()->to(route(self::MERCHANT_OVERVIEW_ROUTE, ['tenant' => $install->shop->slug]));
    }

    /** @param array<string, mixed> $query */
    private function signedAndFresh(array $query): bool
    {
        return ShopifySignatures::query($query) && abs(time() - (int) ($query['timestamp'] ?? 0)) <= self::FRESH_SECONDS;
    }
}
