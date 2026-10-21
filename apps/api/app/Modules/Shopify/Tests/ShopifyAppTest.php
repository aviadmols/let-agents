<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Http\Controllers\ShopifyAppController;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A Shopify store installs the app, becomes a shop, approves the $49 plan and lands in its own
 * panel; what Shopify reports later (a cancelled plan, the app removed, privacy requests) is
 * followed; and a store without a live plan serves no storefront.
 */
final class ShopifyAppTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'gueta-test.myshopify.com';

    private const SECRET = 'shpss_test_secret';

    /** What the fake Shopify says about the store's subscriptions. */
    private array $subscriptions = [];

    /** @var list<array<string, mixed>> GraphQL calls made, in order */
    private array $graphql = [];

    /** Whether the fake store has the app's web pixel already. */
    private bool $pixelTaken = false;

    /** Whether the fake store refuses the pixel: the write_pixels scope not approved yet. */
    private bool $pixelDenied = false;

    /** A token Shopify has withdrawn: its calls answer 401. */
    private ?string $withdrawn = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.key' => 'test-key', 'services.shopify.secret' => self::SECRET, 'app.url' => 'https://agents.lets.co.il']);
        Queue::fake();
        Settings::set('shopify.charge', true);

        Http::fake(function (HttpRequest $request) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                return Http::response(['access_token' => 'shpat_123', 'scope' => 'read_products', 'expires_in' => 86400, 'refresh_token' => 'shprt_1', 'refresh_token_expires_in' => 7776000]);
            }

            if ($this->withdrawn !== null && $request->header('X-Shopify-Access-Token') === [$this->withdrawn]) {
                return Http::response(['errors' => '[API] Invalid API key or access token'], 401);
            }

            $body = (array) json_decode($request->body(), true);
            $this->graphql[] = $body;
            $query = (string) ($body['query'] ?? '');

            if ($this->pixelDenied && str_contains($query, 'webPixel')) {
                return Http::response(['data' => null, 'errors' => [['message' => 'Access denied', 'extensions' => ['code' => 'ACCESS_DENIED']]]]);
            }

            return Http::response(['data' => match (true) {
                str_contains($query, 'webPixelCreate') => ['webPixelCreate' => $this->pixelTaken
                    ? ['webPixel' => null, 'userErrors' => [['field' => null, 'message' => 'taken', 'code' => 'TAKEN']]]
                    : ['webPixel' => ['id' => 'gid://shopify/WebPixel/1'], 'userErrors' => []]],
                str_contains($query, 'webPixelUpdate') => ['webPixelUpdate' => ['webPixel' => ['id' => 'gid://shopify/WebPixel/1'], 'userErrors' => []]],
                str_contains($query, 'webPixel {') => ['webPixel' => ['id' => 'gid://shopify/WebPixel/1']],
                str_contains($query, 'appSubscriptionCreate') => ['appSubscriptionCreate' => ['appSubscription' => ['id' => 'gid://shopify/AppSubscription/1', 'status' => 'PENDING'], 'confirmationUrl' => 'https://gueta-test.myshopify.com/admin/charges/confirm', 'userErrors' => []]],
                str_contains($query, 'activeSubscriptions') => ['currentAppInstallation' => ['activeSubscriptions' => $this->subscriptions], 'shop' => ['plan' => ['partnerDevelopment' => true]]],
                str_contains($query, 'metafieldsSet') => ['metafieldsSet' => ['userErrors' => []]],
                str_contains($query, 'contactEmail') => ['shop' => ['id' => 'gid://shopify/Shop/7', 'name' => 'גואטה טסט', 'email' => 'Owner@Gueta.test', 'currencyCode' => 'ILS', 'ianaTimezone' => 'Asia/Jerusalem', 'primaryDomain' => ['host' => 'shop.gueta.test'], 'plan' => ['partnerDevelopment' => true]]],
                default => ['shop' => ['id' => 'gid://shopify/Shop/7', 'plan' => ['partnerDevelopment' => true]]],
            }]);
        });
    }

    public function test_installing_makes_a_paused_shop_and_asks_for_the_plan(): void
    {
        $authorize = $this->get('/shopify/install?shop='.self::SHOP)->assertRedirect();
        $this->assertStringStartsWith('https://'.self::SHOP.'/admin/oauth/authorize?', $authorize->headers->get('Location'));
        parse_str((string) parse_url((string) $authorize->headers->get('Location'), PHP_URL_QUERY), $asked);

        // The store chooses a plan first, then approves it in Shopify.
        $plans = $this->get('/shopify/callback?'.http_build_query(self::signed(['code' => 'abc', 'state' => $asked['state']])))
            ->assertOk()
            ->assertSeeInOrder(['Essentials', '$49', 'Starter', '$99', 'Growth', '$199', 'Pro', '$299']);
        $this->assertNull(collect($this->graphql)->first(fn (array $call): bool => str_contains((string) $call['query'], 'appSubscriptionCreate')), 'nothing to approve before a plan is chosen');
        $this->get(self::choice($plans, 'growth'))->assertRedirect('https://gueta-test.myshopify.com/admin/charges/confirm');

        $install = ShopifyInstall::query()->sole();
        $shop = Shop::query()->sole();
        $this->assertSame('shpat_123', $install->access_token);
        $this->assertNotSame('shpat_123', $install->getRawOriginal('access_token'), 'tokens are encrypted');
        $this->assertSame([ShopStatus::Paused, 'shop.gueta.test', 'gueta-test'], [$shop->status, $shop->domain, $shop->slug]);
        $this->assertSame('pending', $install->subscription_status);

        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->sole());
        $this->assertSame(['shopify', 'https://shop.gueta.test'], [$connection->platform, $connection->site_url]);
        $this->assertTrue($connection->allowsOrigin('https://gueta-test.myshopify.com'), 'the search runs at the myshopify address too');
        $this->assertTrue(User::query()->where('email', 'owner@gueta.test')->sole()->shops()->whereKey($shop->id)->exists(), 'the store owner manages the shop');

        $metafields = collect($this->graphql)->first(fn (array $call): bool => str_contains((string) $call['query'], 'metafieldsSet'));
        $this->assertSame($connection->site_key, $metafields['variables']['metafields'][0]['value'], 'the theme embed reads the site key from the store');
        $this->assertSame('https://agents.lets.co.il/api/v1', $metafields['variables']['metafields'][1]['value']);

        $plan = collect($this->graphql)->first(fn (array $call): bool => str_contains((string) $call['query'], 'appSubscriptionCreate'))['variables'];
        $this->assertEquals(['Lets Growth', 199, 'USD', 'EVERY_30_DAYS', true], [
            $plan['name'],
            $plan['lineItems'][0]['plan']['appRecurringPricingDetails']['price']['amount'],
            $plan['lineItems'][0]['plan']['appRecurringPricingDetails']['price']['currencyCode'],
            $plan['lineItems'][0]['plan']['appRecurringPricingDetails']['interval'],
            $plan['test'],
        ], 'the chosen plan at its price every 30 days, in test mode on a development store');

        // A link to a plan only works as we signed it.
        $this->get(str_replace('plan=growth', 'plan=pro', self::choice($plans, 'growth')))->assertNotFound();

        Queue::assertPushed(QueuedCommand::class);

        $pixel = collect($this->graphql)->first(fn (array $call): bool => str_contains((string) $call['query'], 'webPixelCreate'));
        $this->assertSame(['siteKey' => $connection->site_key, 'api' => 'https://agents.lets.co.il/api/v1'], json_decode($pixel['variables']['settings'], true), 'the purchase pixel is on, with the store\'s site key');
    }

    public function test_a_store_that_has_the_pixel_gets_it_updated_and_one_without_the_scope_keeps_working(): void
    {
        $install = $this->installed();
        $this->pixelTaken = true;
        $this->graphql = [];

        $this->artisan('shopify:connect-pixel', ['shop' => self::SHOP])->expectsOutputToContain('on')->assertSuccessful();
        $this->assertSame(['webPixelCreate', 'webPixel {', 'webPixelUpdate'], array_map(
            fn (array $call): string => collect(['webPixelCreate', 'webPixelUpdate', 'webPixel {'])->first(fn (string $op): bool => str_contains((string) $call['query'], $op)),
            $this->graphql,
        ), 'the same pixel, updated: never a second one');

        // Without write_pixels Shopify refuses: the store stays installed, the pixel is tried again later.
        $this->pixelDenied = true;
        $this->artisan('shopify:connect-pixel')->expectsOutputToContain('not yet')->assertSuccessful();
        $this->assertTrue($install->refresh()->installed());
    }

    public function test_while_charging_is_off_an_install_is_active_at_once_and_free(): void
    {
        Settings::set('shopify.charge', false);

        $authorize = $this->get('/shopify/install?shop='.self::SHOP)->assertRedirect();
        parse_str((string) parse_url((string) $authorize->headers->get('Location'), PHP_URL_QUERY), $asked);

        $this->get('/shopify/callback?'.http_build_query(self::signed(['code' => 'abc', 'state' => $asked['state']])))
            ->assertRedirect(route('filament.merchant.pages.overview', ['tenant' => 'gueta-test']));

        $install = ShopifyInstall::query()->sole();
        $this->assertSame([ShopifyInstall::FREE, ShopStatus::Active], [$install->subscription_status, $install->shop->status]);
        $this->assertTrue(Features::enabled('search.photos', $install->shop_id) && Features::enabled('retrieval.image_index', $install->shop_id), 'photo search and the picture scan are on from the first day');
        $this->assertNull(collect($this->graphql)->first(fn (array $call): bool => str_contains((string) $call['query'], 'appSubscriptionCreate')), 'nobody is asked to pay');

        // Once charging is on, the free store chooses and approves a plan when it next opens the app.
        Settings::set('shopify.charge', true);
        $plans = $this->get('/shopify/app?'.http_build_query(self::signed([])))->assertOk();
        $this->get(self::choice($plans, 'starter'))->assertRedirect('https://gueta-test.myshopify.com/admin/charges/confirm');
        $this->assertSame(ShopStatus::Paused, $install->shop->fresh()->status);
    }

    public function test_a_store_on_a_custom_app_is_installed_and_renewed_with_that_apps_credentials(): void
    {
        Settings::set('shopify.charge', false);
        config(['services.shopify.more_apps' => 'custom-key:shpss_custom']);
        $tokenAsks = [];
        Http::fake(function (HttpRequest $request) use (&$tokenAsks) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                $tokenAsks[] = $request->data();

                return Http::response(['access_token' => 'shpat_c', 'scope' => 'read_products', 'expires_in' => 86400, 'refresh_token' => 'shprt_c', 'refresh_token_expires_in' => 7776000]);
            }

            return Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/8', 'name' => 'TAK', 'email' => 'tak@tak.test', 'currencyCode' => 'ILS', 'ianaTimezone' => 'Asia/Jerusalem', 'primaryDomain' => ['host' => 'tak.test'], 'plan' => ['partnerDevelopment' => false]], 'metafieldsSet' => ['userErrors' => []], 'currentAppInstallation' => ['activeSubscriptions' => []]]]);
        });

        // Shopify installed the custom app and opens it: the link is signed with the custom secret.
        $open = self::signed([], 'shpss_custom');
        $this->get('/shopify/app?'.http_build_query($open))->assertRedirect('/shopify/install?'.http_build_query(['shop' => self::SHOP, 'app' => 'custom-key']));

        $authorize = $this->get('/shopify/install?'.http_build_query(['shop' => self::SHOP, 'app' => 'custom-key']))->assertRedirect();
        parse_str((string) parse_url((string) $authorize->headers->get('Location'), PHP_URL_QUERY), $asked);
        $this->assertSame('custom-key', $asked['client_id']);

        $this->get('/shopify/callback?'.http_build_query(self::signed(['code' => 'abc', 'state' => $asked['state']], 'shpss_custom')))->assertRedirect();

        $install = ShopifyInstall::query()->sole();
        $this->assertSame(['custom-key', ShopStatus::Active], [$install->client_id, $install->shop->status]);
        $this->assertSame(['custom-key', 'shpss_custom'], [$tokenAsks[0]['client_id'], $tokenAsks[0]['client_secret']]);

        app(ShopifyApi::class)->renew($install);
        $this->assertSame(['custom-key', 'shpss_custom'], [$tokenAsks[1]['client_id'], $tokenAsks[1]['client_secret']], 'renewed with the app it was installed from');

        // A link signed by neither app is refused.
        $this->get('/shopify/app?'.http_build_query(self::signed([], 'shpss_other')))->assertForbidden();
    }

    public function test_an_unsigned_or_replayed_callback_installs_nothing(): void
    {
        Cache::put('shopify:state:s1', self::SHOP, 600);
        $this->get('/shopify/callback?'.http_build_query(['shop' => self::SHOP, 'code' => 'x', 'state' => 's1', 'timestamp' => time(), 'hmac' => 'nope']))->assertForbidden();
        $this->get('/shopify/callback?'.http_build_query(self::signed(['code' => 'x', 'state' => 'never-issued'])))->assertForbidden();
        $this->get('/shopify/install?shop=evil.com')->assertStatus(400);

        $this->assertSame(0, ShopifyInstall::query()->count());
    }

    public function test_an_approved_plan_activates_the_shop_and_opens_its_panel(): void
    {
        $install = $this->installed();
        $this->subscriptions = [['id' => 'gid://shopify/AppSubscription/1', 'name' => 'Lets Growth', 'status' => 'ACTIVE', 'trialDays' => 0, 'createdAt' => now()->toIso8601String()]];

        $this->get('/shopify/billing/return?shop='.self::SHOP)
            ->assertRedirect(route('filament.merchant.pages.overview', ['tenant' => $install->shop->slug]));

        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);
        $this->assertSame('growth', $install->shop->fresh()->plan, 'the shop is on the plan its subscription names');
        $this->assertAuthenticatedAs(User::query()->where('email', 'owner@gueta.test')->sole());

        // Opening the app from the Shopify admin later goes straight to the panel.
        $this->get('/shopify/app?'.http_build_query(self::signed([])))
            ->assertRedirect(route('filament.merchant.pages.overview', ['tenant' => $install->shop->slug]));
    }

    public function test_a_store_owned_by_an_operators_email_opens_with_its_own_account_never_the_operator_or_someone_else(): void
    {
        // The store's owner email is an operator's: Shopify never signs an operator in.
        User::factory()->operator()->create(['email' => 'owner@gueta.test']);
        $install = $this->installed();
        $this->subscriptions = [['id' => 'gid://shopify/AppSubscription/1', 'status' => 'ACTIVE', 'trialDays' => 0, 'createdAt' => now()->toIso8601String()]];

        // Another store's manager is signed in on this browser, from that store's app.
        $other = User::factory()->create();
        $other->attachShop(Shop::factory()->create());
        $this->actingAs($other);

        $overview = route('filament.merchant.pages.overview', ['tenant' => $install->shop->slug]);
        $this->get('/shopify/app?'.http_build_query(self::signed([])))->assertRedirect($overview);

        // Shopify vouches for the store's manager: the store's own account, never the operator.
        $own = User::query()->where('email', 'owner@'.self::SHOP)->sole();
        $this->assertAuthenticatedAs($own);
        $this->assertFalse($own->is_operator);
        $this->assertSame([$install->shop_id], $own->shops()->pluck('shops.id')->all(), 'this shop only');
    }

    public function test_a_token_shopify_withdrew_is_renewed_when_the_app_opens_never_a_500(): void
    {
        $install = $this->installed();
        $this->subscriptions = [['id' => 'gid://shopify/AppSubscription/1', 'status' => 'ACTIVE', 'trialDays' => 0, 'createdAt' => now()->toIso8601String()]];
        // Installed again, and the notice that it was removed never read: the token we kept is dead.
        $install->forceFill(['access_token' => 'shpat_withdrawn', 'client_id' => 'test-key'])->save();
        $this->withdrawn = 'shpat_withdrawn';

        $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4', 'id_token' => self::idToken()])))
            ->assertRedirect();

        $this->assertSame('shpat_123', $install->fresh()->access_token, 'the session token was exchanged for a fresh one');
    }

    public function test_a_declined_plan_keeps_the_shop_paused_and_its_storefront_quiet(): void
    {
        $install = $this->installed();

        $this->get('/shopify/billing/return?shop='.self::SHOP)->assertStatus(402)->assertSee(__('shopify::app.declined_title'));

        $site = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->sole()->site_key);
        $this->getJson("/api/v1/search/{$site}/index")->assertNotFound();
        $this->assertSame(ShopStatus::Paused, $install->shop->fresh()->status);
    }

    public function test_shopify_webhooks_are_signed_and_followed(): void
    {
        $install = $this->installed();
        $install->forceFill(['subscription_status' => 'active', 'subscription_id' => 'gid://shopify/AppSubscription/1'])->save();

        $this->webhook('app_subscriptions/update', ['app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/1', 'status' => 'CANCELLED']], 'wrong')->assertUnauthorized();
        $this->webhook('app_subscriptions/update', ['app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/1', 'status' => 'CANCELLED']])->assertOk();
        $this->assertSame(['cancelled', ShopStatus::Paused], [$install->fresh()->subscription_status, $install->shop->fresh()->status]);

        $this->webhook('app/uninstalled', ['id' => 7])->assertOk();
        $this->assertNull($install->fresh()->access_token);
        $this->assertSame('failed', app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->sole()->status->value));

        $this->webhook('shop/redact', ['shop_domain' => self::SHOP])->assertOk();
        $this->assertSame(0, Shop::query()->count(), 'the shop and all it holds are erased');
    }

    public function test_opened_inside_the_shopify_admin_the_app_installs_by_token_exchange_and_is_framed(): void
    {
        Settings::set('shopify.charge', false);
        $exchanges = [];
        Http::fake(function (HttpRequest $request) use (&$exchanges) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                $exchanges[] = $request->data();

                return Http::response(['access_token' => 'shpat_x', 'scope' => 'read_products', 'expires_in' => 3600, 'refresh_token' => 'shprt_x', 'refresh_token_expires_in' => 7776000]);
            }

            return Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/7', 'name' => 'גואטה טסט', 'email' => 'owner@gueta.test', 'currencyCode' => 'ILS', 'ianaTimezone' => 'Asia/Jerusalem', 'primaryDomain' => ['host' => 'shop.gueta.test'], 'plan' => ['partnerDevelopment' => true]], 'metafieldsSet' => ['userErrors' => []], 'currentAppInstallation' => ['activeSubscriptions' => []]]]);
        });

        $token = self::idToken();
        $opened = $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4', 'locale' => 'he-IL', 'id_token' => $token])));
        $opened->assertRedirect();
        $this->assertSame('he', User::query()->where('email', 'owner@gueta.test')->sole()->locale, 'the merchant\'s Shopify language is theirs here too');
        $this->assertSame('frame-ancestors https://'.self::SHOP.' https://admin.shopify.com;', $opened->headers->get('Content-Security-Policy'), 'only this shop\'s admin may frame it');
        $this->assertSame(['urn:ietf:params:oauth:grant-type:token-exchange', $token, 'test-key'], [$exchanges[0]['grant_type'], $exchanges[0]['subject_token'], $exchanges[0]['client_id']], 'the token comes by exchange, with no screen between');

        $install = ShopifyInstall::query()->sole();
        $this->assertSame(['shpat_123', 'test-key', ShopStatus::Active], [$install->access_token, $install->client_id, $install->shop->status], 'the token answer is the first fake Shopify answer; installed, on this app, active');
        $panel = (string) $opened->headers->get('Location');
        $this->assertStringContainsString('host=YWRtaW4', $panel, 'the panel keeps what Shopify opened with');

        // The panel inside the frame: framed for this shop only, App Bridge in its head.
        $page = $this->get($panel, ['Sec-Fetch-Dest' => 'iframe'])->assertOk();
        $this->assertSame('frame-ancestors https://'.self::SHOP.' https://admin.shopify.com;', $page->headers->get('Content-Security-Policy'));
        $page->assertSee('<meta name="shopify-api-key" content="test-key">', false)->assertSee('cdn.shopify.com/shopifycloud/app-bridge.js', false)
            ->assertSee('window.__letAgentsFetch', false)->assertSee('failed.status === 419', false);

        // Opened on its own, outside the admin, the same page carries no bridge.
        $this->get(route('filament.merchant.pages.overview', ['tenant' => 'gueta-test']))->assertOk()->assertDontSee('shopify-api-key', false);

        // Opened again in the admin: straight to the panel, no second exchange.
        $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4', 'id_token' => self::idToken()])))->assertRedirect();
        $this->assertCount(1, $exchanges);

        // A refresh inside the admin asks for the screen the merchant was on: they land on it, in their panel.
        $deep = $this->get('/shopify/app/merchant/search/photos?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4', 'id_token' => self::idToken()])));
        $deep->assertRedirect();
        $location = (string) $deep->headers->get('Location');
        $this->assertStringContainsString('/merchant/search/photos?embedded=1', $location);
        $this->assertSame(parse_url($panel, PHP_URL_HOST), parse_url($location, PHP_URL_HOST), 'the shop\'s own panel, never another address');
        $this->get('/shopify/app/elsewhere?'.http_build_query(self::signed(['embedded' => '1', 'id_token' => self::idToken()])))->assertNotFound();
    }

    public function test_a_refresh_at_the_apps_own_address_is_a_screen_never_the_panels_login(): void
    {
        $reload = '#^'.ShopifyAppController::RELOAD_PATH.'$#';

        foreach (['merchant/analytics/search', 'merchant/display', 'merchant/search/photos'] as $screen) {
            $this->assertSame(1, preg_match($reload, $screen), $screen);
        }

        // These live at the app's own address too: the merchant signs in there.
        foreach (['merchant', 'merchant/login', 'merchant/logout'] as $panel) {
            $this->assertSame(0, preg_match($reload, $panel), $panel);
        }
    }

    public function test_a_forged_or_stale_session_token_opens_nothing(): void
    {
        $query = ['shop' => self::SHOP, 'embedded' => '1'];
        $this->get('/shopify/app?'.http_build_query($query + ['id_token' => self::idToken([], 'shpss_wrong')]))->assertForbidden();
        $this->get('/shopify/app?'.http_build_query($query + ['id_token' => self::idToken(['exp' => time() - 120])]))->assertForbidden();
        $this->get('/shopify/app?'.http_build_query($query + ['id_token' => self::idToken(['dest' => 'https://other.myshopify.com', 'iss' => 'https://other.myshopify.com/admin'])]))->assertForbidden();
        $this->get('/shopify/app?'.http_build_query($query + ['id_token' => 'not.a.token']))->assertForbidden();

        $this->assertSame(0, ShopifyInstall::query()->count());
    }

    public function test_from_inside_the_frame_shopifys_own_screens_open_in_the_full_window(): void
    {
        $install = $this->installed();

        // The plan is not approved: inside the frame the plans show, and each opens its approval on top, not in it.
        $plans = $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4'])))
            ->assertOk()
            ->assertSee('target="_top"', false)
            ->assertSee('shopify/billing/start', false);
        $this->get(self::choice($plans, 'pro'))->assertRedirect('https://gueta-test.myshopify.com/admin/charges/confirm');
        $this->assertSame('pending', $install->fresh()->subscription_status);
    }

    /** The signed link a plans page offers for one plan. */
    private static function choice(TestResponse $plans, string $code): string
    {
        preg_match('~href="([^"]*shopify/billing/start[^"]*plan='.$code.'[^"]*)"~', (string) $plans->getContent(), $m);

        return html_entity_decode($m[1] ?? 'missing', ENT_QUOTES);
    }

    private function installed(): ShopifyInstall
    {
        Cache::put('shopify:state:s1', self::SHOP, 600);
        $this->get('/shopify/callback?'.http_build_query(self::signed(['code' => 'abc', 'state' => 's1'])));

        return ShopifyInstall::query()->sole();
    }

    /** @param array<string, mixed> $payload */
    private function webhook(string $topic, array $payload, ?string $hmac = null): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/v1/shopify/webhooks', server: [
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac ?? base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }

    /**
     * A session token as Shopify's admin issues one to an embedded app: HS256 with the app's secret.
     *
     * @param  array<string, mixed>  $claims
     */
    private static function idToken(array $claims = [], string $secret = self::SECRET): string
    {
        $claims += ['iss' => 'https://'.self::SHOP.'/admin', 'dest' => 'https://'.self::SHOP, 'aud' => 'test-key', 'sub' => '1', 'exp' => time() + 60, 'nbf' => time() - 5, 'iat' => time() - 5, 'jti' => 'j1', 'sid' => 's1'];
        $encode = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $encode((string) json_encode($claims));

        return $head.'.'.$body.'.'.$encode(hash_hmac('sha256', $head.'.'.$body, $secret, true));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private static function signed(array $query, string $secret = self::SECRET): array
    {
        $query += ['shop' => self::SHOP, 'timestamp' => (string) time()];
        ksort($query);
        $message = implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($query), $query));

        return $query + ['hmac' => hash_hmac('sha256', $message, $secret)];
    }
}
