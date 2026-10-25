<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Core\Usage\MonthlyUsage;
use App\Modules\Admin\Actions\AskToDeleteShop;
use App\Modules\Admin\Actions\EndShopPlan;
use App\Modules\Admin\Contracts\ShopScan;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Jobs\FirstScanStep;
use App\Modules\Shopify\Actions\InstallStore;
use App\Modules\Shopify\Actions\ManageSubscription;
use App\Modules\Shopify\Http\Controllers\ShopifyAppController;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyApi;
use App\Modules\Shopify\Support\ShopifyApps;
use App\Modules\Shopify\Support\ShopifyConnectionFacts;
use App\Modules\Shopify\Support\ShopifySubscription;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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

    /** What the fake store answers when the app writes its metafields. */
    private array $metafieldErrors = [];

    /** How many products on sale and pages the fake store counts. */
    private int $products = 3412;

    private int $pages = 45;

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
                str_contains($query, 'appSubscriptionCancel') => ['appSubscriptionCancel' => ['appSubscription' => ['id' => 'gid://shopify/AppSubscription/1', 'status' => 'CANCELLED'], 'userErrors' => []]],
                str_contains($query, 'appSubscriptionCreate') => ['appSubscriptionCreate' => ['appSubscription' => ['id' => 'gid://shopify/AppSubscription/1', 'status' => 'PENDING'], 'confirmationUrl' => 'https://gueta-test.myshopify.com/admin/charges/confirm', 'userErrors' => []]],
                str_contains($query, 'activeSubscriptions') => ['currentAppInstallation' => ['activeSubscriptions' => $this->subscriptions], 'shop' => ['plan' => ['partnerDevelopment' => true]]],
                str_contains($query, 'metafieldsSet') => ['metafieldsSet' => ['userErrors' => $this->metafieldErrors]],
                str_contains($query, 'shopOwnerName') => ['shop' => ['shopOwnerName' => 'Dana Gueta']],
                str_contains($query, 'productsCount') => ['productsCount' => ['count' => $this->products], 'pagesCount' => ['count' => $this->pages]],
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
        $this->assertSame('Dana Gueta', User::query()->where('email', 'owner@gueta.test')->sole()->name, 'the owner\'s own name, as Shopify keeps it, not the store\'s');

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

        Queue::assertPushed(FirstScanStep::class, fn (FirstScanStep $job): bool => $job->step === 'catalog' && $job->shopId === $shop->id);

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

    public function test_charging_asks_the_public_apps_stores_to_pay_never_a_custom_app_made_for_one_store(): void
    {
        config(['services.shopify.more_apps' => 'custom-key:shpss_custom']);
        $public = new ShopifyInstall(['client_id' => 'test-key']);
        $custom = new ShopifyInstall(['client_id' => 'custom-key']);
        $before = new ShopifyInstall(['client_id' => null]);

        Settings::set('shopify.charge', false);
        $this->assertFalse(ManageSubscription::charges($public));

        Settings::set('shopify.charge', true);
        $this->assertTrue(ManageSubscription::charges($public));
        $this->assertTrue(ManageSubscription::charges($before), 'installed before the app was recorded: the public one');
        $this->assertFalse(ManageSubscription::charges($custom), 'Rocks\' own app stays as agreed: its search is never paused for a plan');
    }

    public function test_a_new_shop_speaks_the_language_its_products_are_written_in(): void
    {
        $this->assertSame('en', InstallStore::language(['Snowboard Demo', 'The Complete Snowboard', 'Gift Card', 'The Collection Snowboard: Liquid']), 'Shopify\'s sample store');
        $this->assertSame('he', InstallStore::language(['גואטה', 'מברגה Makita DHP 18V', 'Bosch GSR 12V', 'דיסק חיתוך 125 מ"מ']), 'brand names in a Hebrew store');
        $this->assertSame('he', InstallStore::language(['גואטה טסט']), 'no products yet: by its name');
        $this->assertSame('en', InstallStore::language(['']));
    }

    public function test_installing_again_after_asking_to_delete_keeps_the_shop(): void
    {
        Settings::set('shopify.charge', false);
        $install = function (): void {
            $authorize = $this->get('/shopify/install?shop='.self::SHOP)->assertRedirect();
            parse_str((string) parse_url((string) $authorize->headers->get('Location'), PHP_URL_QUERY), $asked);
            $this->get('/shopify/callback?'.http_build_query(self::signed(['code' => 'abc', 'state' => $asked['state']])))->assertRedirect();
        };

        $install();
        $shop = ShopifyInstall::query()->sole()->shop;
        $shop->forceFill(['status' => ShopStatus::Paused, 'cancelled_at' => now(), 'deletion_due_at' => now()->addDays(30), 'deletion_asked_by' => 'owner@example.com'])->save();

        $install();

        $shop->refresh();
        $this->assertFalse($shop->leaving(), 'coming back calls off the deletion');
        $this->assertNull($shop->deletion_asked_by);
        $this->assertSame(ShopStatus::Active, $shop->status, 'and the search is back on the site');
    }

    public function test_a_new_store_starts_its_first_scan_at_install_once_and_a_store_installing_again_only_syncs(): void
    {
        Settings::set('shopify.charge', false);
        $install = $this->installed();

        // The scan's first step is the catalogue: no sync of its own beside it.
        Queue::assertPushed(FirstScanStep::class, 1);
        Queue::assertPushed(FirstScanStep::class, fn (FirstScanStep $job): bool => $job->step === 'catalog' && $job->shopId === $install->shop_id);
        Queue::assertNotPushed(QueuedCommand::class);
        $this->assertFalse(app(ShopScan::class)->progress($install->shop)['finished'], 'the onboarding opens on the scan under way');

        // The manager's "start the scan" goes on with it, never a second one.
        $this->assertTrue(app(ShopScan::class)->start($install->shop));
        Queue::assertPushed(FirstScanStep::class, 1);

        // Installed again: the same shop, its catalogue synced, no scan started by the install.
        $this->installed();
        Queue::assertPushed(FirstScanStep::class, 1);
        Queue::assertPushed(QueuedCommand::class, 1);
    }

    public function test_metafields_shopify_refuses_are_logged_with_the_store(): void
    {
        Log::spy();
        $this->metafieldErrors = [['field' => ['metafields', '0', 'value'], 'message' => 'Value is invalid']];

        $this->installed();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'shopify.metafields.not_set'
            && $context['shop'] === self::SHOP
            && $context['errors'][0]['message'] === 'Value is invalid');
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

        // Back from Shopify's approval: the subscription is read, and the merchant goes back into the app
        // inside the admin. The return link signs nobody in: anyone may follow it with a store's address.
        $this->get('/shopify/billing/return?shop='.self::SHOP)
            ->assertRedirect('https://'.self::SHOP.'/admin/apps/'.ShopifyApps::key($install->client_id));
        $this->assertGuest();

        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);
        $this->assertSame('growth', $install->shop->fresh()->plan, 'the shop is on the plan its subscription names');

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

        // declined: back into the app inside the admin, which offers the plans again; nobody signed in
        $this->get('/shopify/billing/return?shop='.self::SHOP)->assertRedirect('https://'.self::SHOP.'/admin/apps/'.ShopifyApps::key($install->client_id));
        $this->assertGuest();

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

        Settings::set('search.results_per_group', 7, $install->shop_id);
        $this->webhook('shop/redact', ['shop_domain' => self::SHOP])->assertOk();
        $this->assertSame(0, Shop::query()->count(), 'the shop and all it holds are erased');
        $this->assertSame(0, DB::table('setting_overrides')->where('scope', $install->shop_id)->count(), 'its own settings too, which have no foreign key');
    }

    public function test_the_trial_is_one_per_store_a_plan_change_or_a_new_install_carries_only_what_is_left(): void
    {
        Settings::set('shopify.trial_days', 14);
        $this->travelTo(now()->startOfSecond()); // Shopify's createdAt is in whole seconds
        $install = $this->installed();
        $trialAsked = function () use ($install): int {
            app(ManageSubscription::class)->start($install->fresh(), 'https://agents.lets.co.il/shopify/billing/return');

            return (int) collect($this->graphql)->last(fn (array $call): bool => str_contains((string) $call['query'], 'appSubscriptionCreate'))['variables']['trialDays'];
        };

        $this->assertSame(14, $trialAsked(), 'a store that never had a plan: the whole trial');

        // Approved with its trial, then the plan changed four days in: ten days left, never fourteen again.
        $this->subscriptions = [['id' => 'gid://shopify/AppSubscription/1', 'name' => 'Lets Growth', 'status' => 'ACTIVE', 'trialDays' => 14, 'createdAt' => now()->toIso8601String()]];
        app(ManageSubscription::class)->refresh($install->fresh());
        $this->travel(4)->days();
        $this->assertSame(10, $trialAsked());
        $this->travel(3)->hours();
        $this->assertSame(9, $trialAsked(), 'whole days, rounded down: changing plans never lengthens it');

        // Removed and installed again within the trial: what is left of it.
        $this->webhook('app/uninstalled', ['id' => 7])->assertOk();
        $this->installed();
        $this->assertSame(9, $trialAsked());

        // The trial over: none.
        $this->travel(11)->days();
        $this->assertSame(0, $trialAsked());

        // A plan paid without a trial (the trial's end not known): none either.
        $install->fresh()->forceFill(['trial_ends_at' => null, 'subscribed_at' => now()->subDays(40)])->save();
        $this->assertSame(0, $trialAsked());
    }

    public function test_the_merchant_switches_lets_on_in_the_theme_with_one_link(): void
    {
        $install = $this->installed();

        $url = app(ShopifyConnectionFacts::class)->facts($install->shop)['embed_url'] ?? null;

        $this->assertSame('https://'.self::SHOP.'/admin/themes/current/editor?context=apps&activateAppId=test-key/app-embed', $url, 'the theme editor opens with the app embed on: Shopify\'s deep link');
        $this->assertFileExists(base_path('../../extensions/shopify/extensions/let-agents-search/blocks/'.ShopifyConnectionFacts::EMBED.'.liquid'), 'the link names the embed block that exists');

        // and each plan, straight to Shopify's approval of it
        $growth = (string) (app(ShopifyConnectionFacts::class)->facts($install->shop)['plan_urls']['growth'] ?? '');
        $this->assertStringContainsString('/shopify/billing/start?', $growth);
        $this->assertStringContainsString('plan=growth', $growth);
        $this->assertStringContainsString('signature=', $growth);
    }

    public function test_the_owner_ending_the_plan_cancels_it_in_shopify_and_a_shop_being_deleted_stays_paused(): void
    {
        $install = $this->installed();
        $install->forceFill(['subscription_status' => 'active', 'subscription_id' => 'gid://shopify/AppSubscription/1'])->save();
        $install->shop->forceFill(['status' => ShopStatus::Active])->save();
        $billing = app(ShopifySubscription::class);

        $this->assertTrue($billing->bills($install->shop));
        $billing->cancel($install->shop);

        $cancel = collect($this->graphql)->first(fn (array $call): bool => str_contains((string) ($call['query'] ?? ''), 'appSubscriptionCancel'));
        $this->assertSame('gid://shopify/AppSubscription/1', $cancel['variables']['id'] ?? null, 'Shopify stops billing at once');
        $this->assertSame(['cancelled', ShopStatus::Paused], [$install->fresh()->subscription_status, $install->shop->fresh()->status]);
        $this->assertFalse($billing->bills($install->shop->fresh()));

        // A plan approved again brings the shop back, unless its owner asked for it to be deleted.
        $install->shop->forceFill(['deletion_due_at' => now()->addDays(30)])->save();
        $this->webhook('app_subscriptions/update', ['app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/2', 'status' => 'ACTIVE']])->assertOk();
        $this->assertSame(ShopStatus::Paused, $install->shop->fresh()->status);

        $install->shop->forceFill(['deletion_due_at' => null, 'cancelled_at' => now()])->save();
        $this->webhook('app_subscriptions/update', ['app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/2', 'status' => 'ACTIVE']])->assertOk();
        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);
        $this->assertNull($install->shop->fresh()->cancelled_at);
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

        // A store just installed opens on its first-run screens, still with what Shopify opened with.
        $welcome = (string) $this->get($panel, ['Sec-Fetch-Dest' => 'iframe'])->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/merchant/gueta-test/welcome?', $welcome);
        $this->assertStringContainsString('host=YWRtaW4', $welcome);

        // The panel inside the frame: framed for this shop only, App Bridge in its head.
        $page = $this->get($welcome, ['Sec-Fetch-Dest' => 'iframe'])->assertOk();
        $this->assertSame('frame-ancestors https://'.self::SHOP.' https://admin.shopify.com;', $page->headers->get('Content-Security-Policy'));
        $page->assertSee('<meta name="shopify-api-key" content="test-key">', false)->assertSee('cdn.shopify.com/shopifycloud/app-bridge.js', false)
            ->assertSee('window.__letAgentsFetch', false)->assertSee('failed.status === 419', false);

        // Opened on its own, outside the admin, the same page carries no bridge.
        $this->get(strtok($welcome, '?'))->assertOk()->assertDontSee('shopify-api-key', false);

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

    public function test_a_cancelled_plan_stops_billing_now_and_keeps_the_search_until_the_paid_period_ends(): void
    {
        Mail::fake();
        User::factory()->operator()->create(['email' => 'team@lets.test']);
        $this->travelTo(now()->startOfSecond());
        $install = $this->paying(now()->addDays(12));
        $owner = User::query()->where('email', 'owner@gueta.test')->sole();
        $this->assertTrue(app(EndShopPlan::class)->keepsPaidPeriod($install->shop));

        app(EndShopPlan::class)->handle($install->shop, $owner);

        $cancel = collect($this->graphql)->first(fn (array $call): bool => str_contains((string) ($call['query'] ?? ''), 'appSubscriptionCancel'));
        $this->assertSame(['id' => 'gid://shopify/AppSubscription/1', 'prorate' => false], $cancel['variables'], 'cancelled in Shopify now, never billed again, no credit back');
        $shop = $install->shop->fresh();
        $this->assertSame(['cancelled', ShopStatus::Active], [$install->fresh()->subscription_status, $shop->status], 'the search stays on the site');
        $this->assertSame(now()->addDays(12)->toIso8601String(), $shop->plan_ends_at?->toIso8601String(), 'until Shopify\'s currentPeriodEnd');
        $this->assertNotNull($shop->cancelled_at);
        Mail::assertSent(TeamNoticeMail::class, fn (TeamNoticeMail $mail): bool => $mail->kind === TeamNoticeMail::PLAN_ENDED && ! $mail->billedByHand && $mail->until?->equalTo(now()->addDays(12)));

        // What Shopify reports of it, and reads of the subscription, pause nothing before the end.
        $this->subscriptions = [];
        $this->webhook('app_subscriptions/update', ['app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/1', 'status' => 'CANCELLED']])->assertOk();
        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);

        // Opened from the admin meanwhile: the panel, where a plan can be chosen again, not the plans.
        $this->get('/shopify/app?'.http_build_query(self::signed([])))
            ->assertRedirect(route('filament.merchant.pages.overview', ['tenant' => $install->shop->slug]));
        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);

        // The hour after the period ends, the shop pauses; before it, nothing.
        $this->travelTo(now()->addDays(12)->subMinute());
        $this->artisan('shops:end-due-plans')->assertSuccessful();
        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);
        $this->travel(2)->minutes();
        $this->artisan('shops:end-due-plans')->assertSuccessful();
        $this->assertSame(ShopStatus::Paused, $install->shop->fresh()->status);
        $this->assertNull($install->shop->fresh()->plan_ends_at, 'applied once');
        $this->assertNotNull($install->shop->fresh()->cancelled_at);

        // Over: opening the app offers the plans again.
        $this->get('/shopify/app?'.http_build_query(self::signed([])))->assertOk()->assertSee('shopify/billing/start', false);
    }

    public function test_a_plan_cancelled_in_its_free_trial_runs_to_the_trials_end_and_a_store_with_no_period_known_stops_at_once(): void
    {
        Settings::set('shopify.trial_days', 14);
        $this->travelTo(now()->startOfSecond());
        $install = $this->installed();
        $this->subscriptions = [['id' => 'gid://shopify/AppSubscription/1', 'name' => 'Lets Growth', 'status' => 'ACTIVE', 'trialDays' => 14, 'createdAt' => now()->toIso8601String(), 'currentPeriodEnd' => now()->addDays(30)->toIso8601String()]];
        app(ManageSubscription::class)->refresh($install->fresh());
        $trialEnds = now()->addDays(14);
        $this->travel(3)->days();

        $ends = app(ShopifySubscription::class)->cancel($install->shop);

        $this->assertSame($trialEnds->toIso8601String(), $ends?->toIso8601String(), 'nothing was billed: the service ends with the trial');
        $this->assertSame([ShopStatus::Active, $trialEnds->toIso8601String()], [$install->shop->fresh()->status, $install->shop->fresh()->plan_ends_at?->toIso8601String()]);

        // Neither a trial nor a period Shopify gives: the service ends now.
        $install->shop->fresh()->forceFill(['plan_ends_at' => null, 'status' => ShopStatus::Active])->save();
        $install->fresh()->forceFill(['subscription_status' => 'active', 'trial_ends_at' => null])->save();
        $this->subscriptions = [];
        $this->assertNull(app(ShopifySubscription::class)->cancel($install->shop));
        $this->assertSame(ShopStatus::Paused, $install->shop->fresh()->status);
    }

    public function test_choosing_a_plan_again_before_the_end_clears_it(): void
    {
        $install = $this->paying(now()->addDays(10));
        app(EndShopPlan::class)->handle($install->shop, User::query()->where('email', 'owner@gueta.test')->sole());
        $this->assertNotNull($install->shop->fresh()->plan_ends_at);

        $this->webhook('app_subscriptions/update', ['app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/2', 'status' => 'ACTIVE']])->assertOk();

        $shop = $install->shop->fresh();
        $this->assertSame([ShopStatus::Active, null, null], [$shop->status, $shop->plan_ends_at, $shop->cancelled_at], 'a plan again: nothing runs out');
        $this->travel(11)->days();
        $this->artisan('shops:end-due-plans')->assertSuccessful();
        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status, 'the old end never pauses it');
    }

    public function test_removing_the_app_or_asking_to_delete_the_shop_ends_a_paid_period_at_once(): void
    {
        Mail::fake();
        $install = $this->paying(now()->addDays(10));
        $owner = User::query()->where('email', 'owner@gueta.test')->sole();
        app(EndShopPlan::class)->handle($install->shop, $owner);
        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);

        // The app removed: no more search, whatever was paid.
        $this->webhook('app/uninstalled', ['id' => 7])->assertOk();
        $this->assertSame([ShopStatus::Paused, null], [$install->shop->fresh()->status, $install->shop->fresh()->plan_ends_at]);

        // Installed again and paying, then the shop asked to be deleted: it leaves the site now.
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        $install = $this->paying(now()->addDays(10));
        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);
        app(AskToDeleteShop::class)->handle($install->shop->fresh(), $owner);

        $shop = $install->shop->fresh();
        $this->assertSame([ShopStatus::Paused, null, 'cancelled'], [$shop->status, $shop->plan_ends_at, $install->fresh()->subscription_status]);
        $this->assertTrue($shop->leaving());
    }

    public function test_a_store_charged_nothing_that_ends_its_plan_stops_at_once(): void
    {
        Settings::set('shopify.charge', false);
        $install = $this->installed();
        $this->assertSame([ShopifyInstall::FREE, ShopStatus::Active], [$install->fresh()->subscription_status, $install->shop->fresh()->status]);
        $this->assertFalse(app(EndShopPlan::class)->keepsPaidPeriod($install->shop));

        app(EndShopPlan::class)->handle($install->shop, User::query()->where('email', 'owner@gueta.test')->sole(), tellTeam: false);

        $this->assertSame([ShopStatus::Paused, null], [$install->shop->fresh()->status, $install->shop->fresh()->plan_ends_at]);
    }

    public function test_the_plans_page_speaks_the_shopify_admins_language_and_shows_the_free_trial(): void
    {
        Settings::set('shopify.trial_days', 14);
        $install = $this->installed();
        $owner = User::query()->where('email', 'owner@gueta.test')->sole();
        $owner->forceFill(['locale' => 'he'])->save();

        // An English admin: the English page, whatever the owner's account says, and its links carry the language on.
        $english = $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4', 'locale' => 'en-US'])))
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('Choose a plan')
            ->assertSee('14-day free trial. Billing starts only when it ends.')
            ->assertDontSee('בחירת תוכנית');
        $this->assertStringContainsString('locale=en', self::choice($english, 'growth'));
        $this->get(self::choice($english, 'growth'), ['Sec-Fetch-Dest' => 'iframe'])->assertOk()->assertSee('Continuing in Shopify');

        // A Hebrew admin: the Hebrew page.
        $this->get('/shopify/app?'.http_build_query(self::signed(['embedded' => '1', 'host' => 'YWRtaW4', 'locale' => 'he'])))
            ->assertOk()
            ->assertSee('<html lang="he" dir="rtl">', false)
            ->assertSee('בחירת תוכנית')
            ->assertSee('14 ימי ניסיון חינם. החיוב מתחיל רק בסופם.')
            ->assertDontSee('Choose a plan');

        // No language from Shopify: the owner's account, then English for a store written in English.
        $this->get('/shopify/app?'.http_build_query(self::signed([])))->assertOk()->assertSee('בחירת תוכנית');
        $owner->forceFill(['locale' => null])->save();
        $install->shop->forceFill(['content_locale' => 'en'])->save();
        $this->get('/shopify/app?'.http_build_query(self::signed([])))->assertOk()->assertSee('Choose a plan');

        // From the panel's "change plan", in the panel's language; part of the trial used: what is left of it.
        $this->travelTo(now()->startOfSecond());
        $this->subscriptions = [['id' => 'gid://shopify/AppSubscription/1', 'name' => 'Lets Growth', 'status' => 'ACTIVE', 'trialDays' => 14, 'createdAt' => now()->toIso8601String()]];
        app(ManageSubscription::class)->refresh($install->fresh());
        $this->travel(4)->days();
        $this->get(ShopifyAppController::plansLink(self::SHOP, 'en'))->assertOk()->assertSee('10 days left of your free trial.')->assertSee('Your plan');
        $this->get(ShopifyAppController::plansLink(self::SHOP, 'he'))->assertOk()->assertSee('נשארו לכם 10 ימים מתקופת הניסיון החינם.');

        // The trial over: no trial line.
        $this->travel(11)->days();
        $this->get(ShopifyAppController::plansLink(self::SHOP, 'en'))->assertOk()->assertDontSee('free trial');
    }

    public function test_the_plans_page_says_which_plan_fits_the_store_and_why_a_smaller_one_does_not(): void
    {
        $install = $this->installed();

        // 3,412 products on sale: more than Starter's 2,000, within Growth's 10,000.
        $page = $this->get(ShopifyAppController::plansLink(self::SHOP, 'en'))->assertOk()
            ->assertSee('Your store has 3,412 products and 45 pages.')
            ->assertSee('The plan that fits your store: Growth.')
            ->assertSee('Too small: up to 2,000 products, and the store has 3,412.');
        $this->assertSame(1, substr_count((string) $page->getContent(), 'data-fits'), 'one plan fits: the smallest the store outgrows in nothing');
        $this->get(ShopifyAppController::plansLink(self::SHOP, 'he'))->assertOk()->assertSee('התוכנית שמתאימה לחנות שלכם: Growth.');

        // A small store that already asked more than Essentials' month of answers: Starter.
        $this->products = 120;
        Cache::forget('shopify:plan-fit:'.$install->id);
        for ($i = 0; $i < 310; $i++) {
            app(MonthlyUsage::class)->take((string) $install->shop_id, 'answers', 1000);
        }
        $this->get(ShopifyAppController::plansLink(self::SHOP, 'en'))->assertOk()
            ->assertSee('This month so far: 310 AI answers and 0 photo searches.')
            ->assertSee('The plan that fits your store: Starter.')
            ->assertSee('Too small: 300 AI answers a month, and there were already 310 this month.');
    }

    /** An installed store paying for a plan whose current period ends at $periodEnd, active. */
    private function paying(CarbonInterface $periodEnd): ShopifyInstall
    {
        $install = $this->installed();
        $this->subscriptions = [['id' => 'gid://shopify/AppSubscription/1', 'name' => 'Lets Growth', 'status' => 'ACTIVE', 'trialDays' => 0, 'createdAt' => now()->subDays(18)->toIso8601String(), 'currentPeriodEnd' => $periodEnd->toIso8601String()]];
        app(ManageSubscription::class)->refresh($install->fresh());
        $this->assertSame(ShopStatus::Active, $install->shop->fresh()->status);

        return $install->fresh();
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
