<?php

namespace App\Modules\Shopify\Http\Controllers;

use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Actions\InstallStore;
use App\Modules\Shopify\Actions\ManageSubscription;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\EmbeddedApp;
use App\Modules\Shopify\Support\ShopifyApi;
use App\Modules\Shopify\Support\ShopifyApps;
use App\Modules\Shopify\Support\ShopifySessionToken;
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
 *   GET /shopify/install          from an install link: off to Shopify to agree
 *   GET /shopify/callback         Shopify answers: the store is installed and becomes a shop
 *   GET /shopify/app              the app opened inside the Shopify admin: the shop's own panel,
 *                                 in the admin's frame; a store without our token gets it on the
 *                                 spot, in exchange for the session token Shopify opened with
 *   GET /shopify/billing/return   after the merchant approved (or not) the monthly plan
 *
 * Every request Shopify sends is signed, or carries its session token; a link older than a few
 * minutes is refused, so a copied link never logs anyone in. The signature also says which of
 * our apps (ShopifyApps) the store is using, and its tokens are asked for with that app's
 * credentials. Shopify's own screens cannot live in the frame, so from inside it they open in
 * the full window.
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

        return $this->onward($request, $install, $billing);
    }

    public function open(Request $request, InstallStore $installer, ManageSubscription $billing, ShopifyApi $api): RedirectResponse|Response
    {
        $query = $request->query();
        $shop = ShopifySignatures::shopDomain($request->query('shop'));
        $claims = ShopifySessionToken::claims((string) $request->query('id_token'));

        // Shopify's word, one of two ways: the signed link, or the session token the admin opens the frame with.
        if ($shop === null || (! $this->signedAndFresh($query) && $claims === null) || ($claims !== null && $claims['shop'] !== $shop)) {
            return response(__('shopify::app.bad_request'), 403);
        }

        $app = $claims['app'] ?? ShopifySignatures::queryApp($query) ?? ShopifyApps::key();
        $install = ShopifyInstall::query()->where('shop_domain', $shop)->first();

        if ($install === null || ! $install->installed()) {
            // Shopify installed the app already; the store still needs our token, from the same app.
            if ($claims === null) {
                return $this->outward($request, '/shopify/install?'.http_build_query(['shop' => $shop, 'app' => $app]), $app);
            }

            // Inside the admin: the session token is exchanged for it, with no screen in between.
            $answer = $api->exchange($shop, $app, (string) $request->query('id_token'));

            if ($answer === null) {
                return response(__('shopify::app.unreachable'), 502);
            }

            $install = $installer->handle($shop, $answer);
            $install->forceFill(['client_id' => $app])->save();
        }

        return $this->onward($request, $install, $billing);
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

        return $this->intoPanel($request, $install);
    }

    /** A subscribed store goes to its panel; one without a subscription approves the plan first. */
    private function onward(Request $request, ShopifyInstall $install, ManageSubscription $billing): RedirectResponse|Response
    {
        if (! in_array($billing->refresh($install), [ShopifyInstall::ACTIVE, ShopifyInstall::FREE], true)) {
            $approve = $billing->start($install, url('/shopify/billing/return?'.http_build_query(['shop' => $install->shop_domain])));

            return $this->outward($request, $approve, ShopifyApps::key($install->client_id));
        }

        return $this->intoPanel($request, $install);
    }

    /**
     * The store's owner, signed in by Shopify's word, in the shop's own panel. Inside the admin
     * the panel keeps what Shopify opened with (host, embedded), so App Bridge finds its way.
     */
    private function intoPanel(Request $request, ShopifyInstall $install): RedirectResponse
    {
        $owner = User::query()->whereHas('shops', fn ($q) => $q->where('shops.id', $install->shop_id)->where('shop_user.role', 'owner'))->first();

        if ($owner !== null && ! $owner->is_operator) {
            Auth::login($owner);
            $request->session()->regenerate();
        }

        $url = route(self::MERCHANT_OVERVIEW_ROUTE, ['tenant' => $install->shop->slug]);

        if (EmbeddedApp::framed($request)) {
            $url .= '?'.http_build_query(['embedded' => '1', 'host' => (string) $request->query('host'), 'shop' => $install->shop_domain]);
        }

        return redirect()->to($url);
    }

    /** A Shopify screen of its own: in the full window when the app is framed, straight there otherwise. */
    private function outward(Request $request, string $url, string $app): RedirectResponse|Response
    {
        if (EmbeddedApp::framed($request)) {
            return response(view('shopify::top', ['url' => $url, 'key' => $app]));
        }

        return redirect()->to($url);
    }

    /** @param array<string, mixed> $query */
    private function signedAndFresh(array $query): bool
    {
        return ShopifySignatures::query($query) && abs(time() - (int) ($query['timestamp'] ?? 0)) <= self::FRESH_SECONDS;
    }
}
