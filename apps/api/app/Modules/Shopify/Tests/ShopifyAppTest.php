<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
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

            $body = (array) json_decode($request->body(), true);
            $this->graphql[] = $body;
            $query = (string) ($body['query'] ?? '');

            return Http::response(['data' => match (true) {
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

        $this->get('/shopify/callback?'.http_build_query(self::signed(['code' => 'abc', 'state' => $asked['state']])))
            ->assertRedirect('https://gueta-test.myshopify.com/admin/charges/confirm');

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
        $this->assertEquals([49, 'USD', 'EVERY_30_DAYS', true], [
            $plan['lineItems'][0]['plan']['appRecurringPricingDetails']['price']['amount'],
            $plan['lineItems'][0]['plan']['appRecurringPricingDetails']['price']['currencyCode'],
            $plan['lineItems'][0]['plan']['appRecurringPricingDetails']['interval'],
            $plan['test'],
        ], '$49 every 30 days, in test mode on a development store');

        Queue::assertPushed(QueuedCommand::class);
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
        $this->assertNull(collect($this->graphql)->first(fn (array $call): bool => str_contains((string) $call['query'], 'appSubscriptionCreate')), 'nobody is asked to pay');

        // Once charging is on, the free store approves the plan when it next opens the app.
        Settings::set('shopify.charge', true);
        $this->get('/shopify/app?'.http_build_query(self::signed([])))
            ->assertRedirect('https://gueta-test.myshopify.com/admin/charges/confirm');
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
        $this->subscriptions = [['id' => 'gid://shopify/AppSubscription/1', 'status' => 'ACTIVE', 'trialDays' => 0, 'createdAt' => now()->toIso8601String()]];

        $this->get('/shopify/billing/return?shop='.self::SHOP)
            ->assertRedirect(route('filament.merchant.pages.overview', ['tenant' => $install->shop->slug]));

        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);
        $this->assertAuthenticatedAs(User::query()->where('email', 'owner@gueta.test')->sole());

        // Opening the app from the Shopify admin later goes straight to the panel.
        $this->get('/shopify/app?'.http_build_query(self::signed([])))
            ->assertRedirect(route('filament.merchant.pages.overview', ['tenant' => $install->shop->slug]));
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
        $opened = $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4', 'id_token' => $token])));
        $opened->assertRedirect();
        $this->assertSame('frame-ancestors https://'.self::SHOP.' https://admin.shopify.com;', $opened->headers->get('Content-Security-Policy'), 'only this shop\'s admin may frame it');
        $this->assertSame(['urn:ietf:params:oauth:grant-type:token-exchange', $token, 'test-key'], [$exchanges[0]['grant_type'], $exchanges[0]['subject_token'], $exchanges[0]['client_id']], 'the token comes by exchange, with no screen between');

        $install = ShopifyInstall::query()->sole();
        $this->assertSame(['shpat_123', 'test-key', ShopStatus::Active], [$install->access_token, $install->client_id, $install->shop->status], 'the token answer is the first fake Shopify answer; installed, on this app, active');
        $panel = (string) $opened->headers->get('Location');
        $this->assertStringContainsString('host=YWRtaW4', $panel, 'the panel keeps what Shopify opened with');

        // The panel inside the frame: framed for this shop only, App Bridge in its head.
        $page = $this->get($panel, ['Sec-Fetch-Dest' => 'iframe'])->assertOk();
        $this->assertSame('frame-ancestors https://'.self::SHOP.' https://admin.shopify.com;', $page->headers->get('Content-Security-Policy'));
        $page->assertSee('<meta name="shopify-api-key" content="test-key">', false)->assertSee('cdn.shopify.com/shopifycloud/app-bridge.js', false);

        // Opened on its own, outside the admin, the same page carries no bridge.
        $this->get(route('filament.merchant.pages.overview', ['tenant' => 'gueta-test']))->assertOk()->assertDontSee('shopify-api-key', false);

        // Opened again in the admin: straight to the panel, no second exchange.
        $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4', 'id_token' => self::idToken()])))->assertRedirect();
        $this->assertCount(1, $exchanges);
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

        // The plan is not approved: inside the frame, the approval opens on top, not in it.
        $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4'])))
            ->assertOk()
            ->assertSee('target="_top"', false)
            ->assertSee('https://gueta-test.myshopify.com/admin/charges/confirm', false);
        $this->assertSame('pending', $install->fresh()->subscription_status);
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
