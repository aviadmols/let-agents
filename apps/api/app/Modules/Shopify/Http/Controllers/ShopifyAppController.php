<?php

namespace App\Modules\Shopify\Http\Controllers;

use App\Core\Facades\Settings;
use App\Core\Localization\Locales;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Contracts\StoreFeedUnavailable;
use App\Modules\Shopify\Actions\ConnectPixel;
use App\Modules\Shopify\Actions\InstallStore;
use App\Modules\Shopify\Actions\ManageSubscription;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\EmbeddedApp;
use App\Modules\Shopify\Support\PlanFit;
use App\Modules\Shopify\Support\ShopifyApi;
use App\Modules\Shopify\Support\ShopifyApps;
use App\Modules\Shopify\Support\ShopifySessionToken;
use App\Modules\Shopify\Support\ShopifySignatures;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
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
 *   GET /shopify/plans            the plans to choose from (a link we signed), before approving one
 *   GET /shopify/billing/start    the chosen plan, off to Shopify to approve it (a link we signed)
 *   GET /shopify/billing/return   after the merchant approved (or not) the monthly plan
 *
 * Every request Shopify sends is signed, or carries its session token; a link older than a few
 * minutes is refused, so a copied link never logs anyone in. The signature also says which of
 * our apps (ShopifyApps) the store is using, and its tokens are asked for with that app's
 * credentials. Shopify's own screens cannot live in the frame, so from inside it they open in
 * the full window. The pages shown on Shopify's side speak the admin's language (speak()).
 */
final class ShopifyAppController
{
    /** A signed link from Shopify is good for this long. */
    private const FRESH_SECONDS = 600;

    private const STATE_SECONDS = 600;

    private const MERCHANT_OVERVIEW_ROUTE = 'filament.merchant.pages.overview';

    /** Plan and billing in the shop's panel, where a plan link that ran out returns. */
    private const PLAN_SETTINGS_ROUTE = 'filament.merchant.pages.settings.plan';

    /** What Shopify opens the frame with, kept on every link inside it: never part of a signed link. */
    private const FRAME_QUERY = ['host', 'embedded'];

    /**
     * A screen of a shop's panel, as the Shopify admin asks for it after a refresh, at the app's own
     * address. Never the panel's login or logout, which live at that address too.
     */
    public const RELOAD_PATH = 'merchant/(?!(?:login|logout)(?:/|$))[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*';

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

    public function open(Request $request, InstallStore $installer, ManageSubscription $billing, ShopifyApi $api, ConnectPixel $pixel): RedirectResponse|Response
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
        // A token another app was given for this store: this app needs its own.
        $otherApp = $install !== null && $claims !== null && $install->client_id !== null && $install->client_id !== $app;

        if ($install === null || ! $install->installed() || $otherApp) {
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
        } else {
            // A store from before the pixel: on once the merchant approved the app's new scopes.
            $pixel->once($install);
        }

        try {
            return $this->onward($request, $install, $billing);
        } catch (StoreFeedUnavailable $refused) {
            // Shopify withdrew the token we kept (the app was installed again, perhaps before this
            // server could read the notice that it was removed): a fresh one, then on as before.
            if ($refused->reason !== 'not_installed') {
                throw $refused;
            }

            if ($claims === null) {
                return $this->outward($request, '/shopify/install?'.http_build_query(['shop' => $shop, 'app' => $app]), $app);
            }

            $answer = $api->exchange($shop, $app, (string) $request->query('id_token'));

            if ($answer === null) {
                return response(__('shopify::app.unreachable'), 502);
            }

            $install = $installer->handle($shop, $answer);
            $install->forceFill(['client_id' => $app])->save();

            return $this->onward($request, $install, $billing);
        }
    }

    public function billingReturn(Request $request, ManageSubscription $billing): RedirectResponse|Response
    {
        $shop = ShopifySignatures::shopDomain($request->query('shop'));
        $install = $shop === null ? null : ShopifyInstall::query()->where('shop_domain', $shop)->first();

        if ($install === null || ! $install->installed()) {
            return response(__('shopify::app.bad_request'), 404);
        }

        // Whatever the link says, the subscription is read from Shopify itself. Nobody is signed in here: the
        // link carries no proof of who follows it (anyone who knows a store's address could). Back into the
        // app inside the Shopify admin, which opens it with a session token: the panel when the plan was
        // approved, the plans again when it was declined. The app stays embedded throughout.
        $billing->refresh($install);

        return redirect()->away('https://'.$install->shop_domain.'/admin/apps/'.ShopifyApps::key($install->client_id));
    }

    /** The plans a store chooses from: a signed link, from onward() or from the panel's "Connection and plan". */
    public function plans(Request $request): Response
    {
        $install = $this->signedInstall($request);
        $this->speak($request, $install);

        if ($install === null) {
            return $this->expired($request) ?? response(__('shopify::app.bad_request'), 404);
        }

        return $this->choosePlan($request, $install);
    }

    /** The plan the merchant chose, off to Shopify to approve it, out of the admin's frame. */
    public function startPlan(Request $request, ManageSubscription $billing): RedirectResponse|Response
    {
        $install = $this->signedInstall($request);
        $plan = Plan::query()->offered()->where('code', (string) $request->query('plan'))->first();
        $this->speak($request, $install);

        if ($install === null || $plan === null) {
            return ($install === null ? $this->expired($request) : null) ?? response(__('shopify::app.bad_request'), 404);
        }

        $approve = $billing->start($install, url('/shopify/billing/return?'.http_build_query(['shop' => $install->shop_domain])), $plan);

        return $this->outward($request, $approve, ShopifyApps::key($install->client_id), $install);
    }

    /** A link to the plans, signed for this store, for an hour: the panel offers it as "change plan", in its language. */
    public static function plansLink(string $shopDomain, ?string $locale = null): string
    {
        return self::onAppAddress(fn (): string => URL::temporarySignedRoute('shopify.plans', now()->addHour(), ['shop' => $shopDomain] + self::speaking($locale)));
    }

    /**
     * One plan, signed for this store, for an hour: off to Shopify to approve it (the panel's upgrade or downgrade).
     * Inside the admin's frame it carries what Shopify opened the frame with ($frame: host, embedded), so the next
     * page in the frame has App Bridge too, which alone may take the frame's window to Shopify's approval.
     *
     * @param  array{host?: string, embedded?: string}  $frame
     */
    public static function planLink(string $shopDomain, string $plan, ?string $locale = null, array $frame = []): string
    {
        return self::onAppAddress(fn (): string => URL::temporarySignedRoute('shopify.billing.start', now()->addHour(), ['shop' => $shopDomain, 'plan' => $plan] + self::speaking($locale) + $frame));
    }

    /**
     * A link on the app's own address (app.url, the address Shopify knows the app by), wherever it is made. The panel
     * lives on the shop's address (gueta.agents.lets.co.il), and App Bridge on a page there never reaches the admin:
     * the step to Shopify's approval waited forever, its button too (App Store review, 11.2026).
     *
     * @param  callable(): string  $link
     */
    private static function onAppAddress(callable $link): string
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($root === '') {
            return $link();
        }

        URL::forceRootUrl($root);

        try {
            return $link();
        } finally {
            URL::forceRootUrl(null);
        }
    }

    /** @return array{locale?: string} the language a signed link carries on to the page it opens */
    private static function speaking(?string $locale): array
    {
        return Locales::isSupported($locale) ? ['locale' => (string) $locale] : [];
    }

    /**
     * The offered plans, each a signed link to approve it; the shop's own plan marked; the plan that fits the store
     * (PlanFit: its products, its pages, this month's use) and what each smaller one is short of; and the free trial
     * the store gets (shopify.trial_days, or what is left of it), where it is asked to pay at all. In the admin's language.
     */
    private function choosePlan(Request $request, ShopifyInstall $install): Response
    {
        $locale = $this->speak($request, $install);
        $current = $install->subscribed() ? Shop::query()->find($install->shop_id)?->plan : null;
        $offered = Plan::query()->offered()->get();
        $store = app(PlanFit::class)->store($install);
        $fit = PlanFit::choose($offered, $store);
        // Inside the admin's frame, a choice stays in the frame with what Shopify opened it with: the step it opens
        // has App Bridge, which takes the window to Shopify's approval. A plain jump out of the frame did nothing in
        // App Store review (11.11: "buttons are not working"): App Bridge kept our own address in the frame, and the
        // page there, opened without host, had no App Bridge to leave it.
        $frame = EmbeddedApp::framed($request) && filled($request->query('host'))
            ? ['embedded' => '1', 'host' => (string) $request->query('host')] : [];
        $plans = $offered->map(fn (Plan $plan): array => [
            'plan' => $plan,
            'current' => $plan->code === $current,
            'fits' => $plan->code === $fit['fits'],
            'short' => $fit['short'][$plan->code] ?? [],
            'lines' => $plan->lines(),
            'url' => self::planLink($install->shop_domain, $plan->code, $locale, $frame),
        ])->all();

        $trial = null;
        $left = ManageSubscription::trialDaysLeft($install);

        if (ManageSubscription::charges($install) && $left > 0) {
            $whole = $left >= (int) Settings::get('shopify.trial_days');
            $trial = trans_choice($whole ? 'shopify::app.plans_trial' : 'shopify::app.plans_trial_left', $left, ['days' => $left]);
        }

        return response(view('shopify::plans', ['plans' => $plans, 'trial' => $trial, 'store' => $store, 'fits' => $offered->firstWhere('code', $fit['fits'])?->name, 'key' => ShopifyApps::key($install->client_id)]));
    }

    /**
     * The language of a page the app shows on Shopify's side (the plans, the step out of the admin's frame): the
     * Shopify admin's, which Shopify opens the app with (locale=en, he, en-US...) and our signed links carry on; else
     * the store owner's account; else English for a store written in English; else the panel's default.
     */
    private function speak(Request $request, ?ShopifyInstall $install): string
    {
        $asked = $request->query('locale');
        $owner = $install === null ? null : app(InstallStore::class)->ownerAccount($install)?->preferredLocale();
        $content = $install === null ? '' : strtolower((string) Shop::query()->whereKey($install->shop_id)->value('content_locale'));
        $default = (string) Settings::get('admin.default_locale');

        $locale = Locales::negotiate(is_string($asked) ? $asked : null)
            ?? $owner
            ?? (str_starts_with($content, 'en') && Locales::isSupported('en') ? 'en' : null)
            ?? (Locales::isSupported($default) ? $default : Locales::fallback());

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $locale;
    }

    /**
     * A link of ours whose hour ran out (Plan and billing left open, a press later): back to Plan and billing, whose
     * links are fresh, in the frame as it was; never an error page. A link that was never ours gets nothing here.
     */
    private function expired(Request $request): ?RedirectResponse
    {
        $ours = URL::hasCorrectSignature($request) || URL::hasCorrectSignature($request, true, self::FRAME_QUERY);
        $shop = $ours && ! URL::signatureHasNotExpired($request) ? ShopifySignatures::shopDomain($request->query('shop')) : null;
        $install = $shop === null ? null : ShopifyInstall::query()->where('shop_domain', $shop)->first();

        if ($install === null || ! $install->installed() || $install->shop === null) {
            return null;
        }

        $url = route(self::PLAN_SETTINGS_ROUTE, ['tenant' => $install->shop->slug]);

        if (EmbeddedApp::framed($request)) {
            $url .= '?'.http_build_query(['embedded' => '1', 'host' => (string) $request->query('host'), 'shop' => $install->shop_domain]);
        }

        return redirect()->to($url);
    }

    private function signedInstall(Request $request): ?ShopifyInstall
    {
        // Inside the admin's frame the panel adds what Shopify opened the frame with (host, embedded) to every link it
        // follows, this one too. They were never signed, and a signature that counted them refused every plan button
        // in the panel (App Store review, 11.2026). A link the plans page signed with them (choosePlan) is whole as it is.
        // The store itself stays signed either way.
        $signed = $request->hasValidSignature() || $request->hasValidSignatureWhileIgnoring(self::FRAME_QUERY);
        $shop = $signed ? ShopifySignatures::shopDomain($request->query('shop')) : null;
        $install = $shop === null ? null : ShopifyInstall::query()->where('shop_domain', $shop)->first();

        return $install !== null && $install->installed() ? $install : null;
    }

    /**
     * A subscribed store goes to its panel, and so does one whose cancelled plan still runs to the end of the period
     * already paid (it chooses a plan again there, on "Connection and plan"); one without a subscription chooses a
     * plan and approves it first.
     */
    private function onward(Request $request, ShopifyInstall $install, ManageSubscription $billing): RedirectResponse|Response
    {
        $live = in_array($billing->refresh($install), [ShopifyInstall::ACTIVE, ShopifyInstall::FREE], true)
            || Shop::query()->find($install->shop_id)?->planRunsUntil() !== null;

        if (! $live) {
            if (Plan::query()->offered()->exists()) {
                return $this->choosePlan($request, $install);
            }

            $approve = $billing->start($install, url('/shopify/billing/return?'.http_build_query(['shop' => $install->shop_domain])));

            return $this->outward($request, $approve, ShopifyApps::key($install->client_id), $install);
        }

        return $this->intoPanel($request, $install);
    }

    /**
     * The store's owner, signed in by Shopify's word, in the shop's own panel. Inside the admin
     * the panel keeps what Shopify opened with (host, embedded), so App Bridge finds its way.
     */
    private function intoPanel(Request $request, ShopifyInstall $install): RedirectResponse
    {
        $owner = app(InstallStore::class)->manager($install);

        if ($owner !== null && ! $owner->is_operator) {
            // The merchant's language in Shopify is theirs here too, until they choose another.
            if ($owner->locale === null && ($locale = Locales::negotiate($request->query('locale'))) !== null) {
                $owner->forceFill(['locale' => $locale])->save();
            }

            Auth::login($owner);
            $request->session()->regenerate();
        }

        // Someone else is signed in on this browser (another store's manager, from that store's app):
        // they may not open this shop, and the panel would answer 404. Out, to the sign-in page, which
        // comes back here. An operator who owns the store signs in once; Shopify never signs them in.
        $user = Auth::user();

        if ($user instanceof User && ! $user->canAccessTenant($install->shop)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $url = route(self::MERCHANT_OVERVIEW_ROUTE, ['tenant' => $install->shop->slug]);
        // After a refresh inside Shopify: the screen the merchant was on, in their own shop's panel.
        $path = (string) $request->route('path');

        if (preg_match('#^merchant(/[A-Za-z0-9_-]+)+$#', $path) === 1) {
            $parts = parse_url($url);
            $url = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').'/'.$path;
        }

        if (! Auth::check()) {
            $request->session()->put('url.intended', $url);
        }

        if (EmbeddedApp::framed($request)) {
            $url .= '?'.http_build_query(['embedded' => '1', 'host' => (string) $request->query('host'), 'shop' => $install->shop_domain]);
        }

        return redirect()->to($url);
    }

    /** A Shopify screen of its own: in the full window when the app is framed, straight there otherwise. */
    private function outward(Request $request, string $url, string $app, ?ShopifyInstall $install = null): RedirectResponse|Response
    {
        if (EmbeddedApp::framed($request)) {
            $this->speak($request, $install);

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
