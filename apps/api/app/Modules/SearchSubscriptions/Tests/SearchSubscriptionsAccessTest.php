<?php

namespace App\Modules\SearchSubscriptions\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\SearchSubscriptions\Actions\RemindEnding;
use App\Modules\SearchSubscriptions\Actions\SendWeekly;
use App\Modules\SearchSubscriptions\Filament\Merchant\Pages\SearchSubscriptionsSettings;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionProduct;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use App\Modules\SearchSubscriptions\Support\Availability;
use App\Modules\SearchSubscriptions\Support\BrowserTokens;
use App\Modules\SearchSubscriptions\Support\FollowedSearches;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who has Search Subscriptions and what reaches the storefront: off by default; only a store on the Growth plan
 * (search_subscriptions.plans) with the feature on gets the drawer's offer, a sign-up, an email; the plan is read, never
 * changed. Also: offensive words are not kept, a hard bounce stops an address everywhere, and the owner's page.
 */
final class SearchSubscriptionsAccessTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_ffffffffffffffffffffffffffffffffffffffffffffffff';

    private const ORIGIN = 'https://store.test';

    private const SECRET = 'whsec_c2VjcmV0LWZvci10ZXN0cy0xMjM0NTY3ODkw';

    private Shop $shop;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->shop = Shop::factory()->create(['name' => 'Gueta Avigdor', 'timezone' => 'Asia/Jerusalem', 'domain' => 'store.test', 'plan' => 'growth']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => self::ORIGIN, 'access_token' => self::TOKEN, 'status' => ConnectionStatus::Connected,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
        $json = (string) json_encode(['items' => [], 'facets' => [], 'actions' => []]);
        app(TenantContext::class)->run($this->shop->id, fn () => SearchIndex::query()->create(['shop_id' => $this->shop->id, 'hash' => md5($json), 'items' => $json, 'counts' => [], 'built_at' => now()]));
        $this->app->instance(ProductsForQuery::class, new class implements ProductsForQuery
        {
            public function find(string $shopId, string $query, int $limit): array
            {
                return [];
            }
        });
    }

    public function test_it_is_off_by_default_and_the_drawer_shows_nothing_of_it(): void
    {
        $this->assertFalse(Features::enabled('search_subscriptions.enabled', $this->shop->id));
        $this->assertTrue(Features::enabled('search_subscriptions.offered', $this->shop->id), 'the owner\'s page is offered; the service waits for the owner to turn it on');
        $this->assertFalse(Availability::on($this->shop->id));
        $this->assertNull($this->drawerConfig()['subscribe'] ?? null);
        $this->subscribe('dana@example.com', 'ג׳קט ג׳ינס')->assertStatus(403)->assertJsonPath('error', 'not_enabled');
        Mail::assertNothingSent();
    }

    public function test_on_for_a_growth_store_the_drawer_gets_the_offer_and_a_sign_up_works(): void
    {
        Features::override('search_subscriptions.enabled', true, $this->shop->id);
        Settings::set('search_subscriptions.placement', 'no_match', $this->shop->id);

        $this->assertSame(['at' => 'no_match', 'store' => 'Gueta Avigdor'], $this->drawerConfig()['subscribe'] ?? null);
        $response = $this->subscribe('dana@example.com', 'ג׳קט ג׳ינס')->assertOk()->assertJsonPath('status', 'confirm_sent')->assertJsonPath('confirmed', false);

        // the bar's own calls: the token says pending, then stops it; it reveals no address
        $token = (string) $response->json('token');
        $this->assertNotNull(BrowserTokens::read($token));
        $this->assertStringNotContainsString('dana', (string) $response->getContent());
        $this->bar('/subscription', $token)->assertOk()->assertJsonPath('status', 'pending');
        $this->bar('/subscription/stop', $token)->assertOk();
        $this->bar('/subscription', $token)->assertJsonPath('status', 'stopped');
    }

    public function test_a_store_on_another_plan_or_none_gets_no_offer_no_sign_up_and_no_email_even_with_the_feature_on(): void
    {
        $plan = Shop::query()->whereKey($this->shop->id)->value('plan');
        $subscription = $this->confirmed('dana@example.com', 'jeans jacket', now()->subDays(85));

        foreach (['starter', 'essentials', 'enterprise', null] as $other) {
            $this->shop->forceFill(['plan' => $other])->save();
            Features::override('search_subscriptions.enabled', true, $this->shop->id);

            $this->assertFalse(Availability::on($this->shop->id), 'plan '.($other ?? 'none'));
            $this->assertFalse(Availability::offered($this->shop->id));
            $this->assertNull($this->drawerConfig()['subscribe'] ?? null);
            $this->subscribe('ron@example.com', 'white sneakers')->assertStatus(403);
            $this->assertSame(0, app(RemindEnding::class)->handle());
        }
        Mail::assertNothingSent();
        $this->assertSame('growth', $plan, 'the test changes the plan itself; the feature never does');

        // the operator may name more plans
        Settings::set('search_subscriptions.plans', 'growth, pro');
        $this->shop->forceFill(['plan' => 'pro'])->save();
        $this->assertTrue(Availability::on($this->shop->id));
        $this->assertSame(SearchSubscription::ACTIVE, $subscription->refresh()->status, 'a plan change stops no subscription: sending just waits');
    }

    public function test_the_weekly_email_and_the_reminder_wait_while_the_plan_does_not_carry_it(): void
    {
        Features::override('search_subscriptions.enabled', true, $this->shop->id);
        $this->confirmed('dana@example.com', 'jeans jacket', now()->subDays(85));
        $this->shop->forceFill(['plan' => 'starter'])->save();

        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        $this->assertSame(0, app(RemindEnding::class)->handle());
        Mail::assertNothingSent();
    }

    public function test_a_search_with_an_offensive_word_is_not_kept(): void
    {
        Features::override('search_subscriptions.enabled', true, $this->shop->id);
        Settings::set('search_subscriptions.signups_per_minute', 60);

        $this->assertTrue(Subscribers::offensive('fucking jeans'));
        $this->assertTrue(Subscribers::offensive('חולצה של הזונה'));
        $this->assertFalse(Subscribers::offensive('כוס קפה'), 'a cup is a cup');
        $this->assertFalse(Subscribers::offensive('shitake mushrooms'), 'a mushroom is a mushroom');
        $this->subscribe('dana@example.com', 'fuck this jacket')->assertStatus(422)->assertJsonPath('status', 'not_keepable');

        Settings::set('search_subscriptions.blocked_words', 'מילה רעה, badword');
        $this->subscribe('dana@example.com', 'jacket badword')->assertStatus(422)->assertJsonPath('status', 'not_keepable');
        $this->subscribe('dana@example.com', 'ג׳קט עם מילה רעה')->assertStatus(422);
        $this->subscribe('dana@example.com', 'ג׳קט ג׳ינס')->assertOk();
    }

    public function test_a_hard_bounce_or_a_complaint_stops_the_address_in_every_store_and_an_unsigned_one_is_refused(): void
    {
        config(['services.resend.webhook_secret' => self::SECRET]);
        $mine = $this->confirmed('dana@example.com', 'jeans jacket', now()->subDay());
        $other = Shop::factory()->create();
        $theirs = app(TenantContext::class)->run($other->id, fn () => SearchSubscription::query()->create(['shop_id' => $other->id, 'email' => 'dana@example.com',
            'email_hash' => Subscribers::hash('dana@example.com'), 'query' => 'x', 'query_key' => 'x', 'consent_text' => 'x', 'consent_at' => now(), 'status' => 'active']));
        $ron = $this->confirmed('ron@example.com', 'jeans jacket', now()->subDay());

        $bounce = (string) json_encode(['type' => 'email.bounced', 'data' => ['to' => ['Dana@Example.com']]]);
        $this->webhook($bounce, 'v1,'.base64_encode('nope'))->assertStatus(401);
        $this->webhook($bounce, null, time() - 3600)->assertStatus(401);
        $this->assertSame(SearchSubscription::ACTIVE, $mine->refresh()->status);

        $this->webhook((string) json_encode(['type' => 'email.delivered', 'data' => ['to' => ['dana@example.com']]]))->assertOk()->assertJsonPath('status', 'ignored');
        $this->webhook($bounce)->assertOk()->assertJsonPath('stopped', 2);
        $this->assertSame(['stopped', 'bounced'], [$mine->refresh()->status, $mine->stop_reason]);
        $this->assertSame('stopped', app(TenantContext::class)->runUnscoped(fn () => $theirs->refresh()->status));
        $this->assertSame(SearchSubscription::ACTIVE, $ron->refresh()->status);

        $this->webhook((string) json_encode(['type' => 'email.complained', 'data' => ['to' => ['ron@example.com']]]))->assertOk();
        $this->assertSame(['stopped', 'complained'], [$ron->refresh()->status, $ron->stop_reason]);

        config(['services.resend.webhook_secret' => null]);
        $this->webhook($bounce)->assertStatus(401);
    }

    public function test_the_owners_page_shows_for_a_growth_store_it_was_offered_to_with_the_searches_people_follow(): void
    {
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);

        Features::override('search_subscriptions.offered', false);
        $this->assertFalse(SearchSubscriptionsSettings::canAccess(), 'not offered');
        Features::override('search_subscriptions.offered', true);
        $this->assertTrue(SearchSubscriptionsSettings::canAccess());
        $this->shop->forceFill(['plan' => 'starter'])->save();
        $this->assertFalse(SearchSubscriptionsSettings::canAccess(), 'not on this plan');
        $this->shop->forceFill(['plan' => 'growth'])->save();

        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $this->confirmed($email, 'Jeans jacket', now()->subDays(2));
        }
        $this->confirmed('d@example.com', 'jeans  jacket', now()->subDays(2));
        $this->confirmed('a@example.com', 'נעלי ספורט', now()->subDays(2));
        $send = $this->inShop(fn () => SearchSubscriptionSend::query()->create(['shop_id' => $this->shop->id, 'email_hash' => Subscribers::hash('a@example.com'), 'queries' => ['Jeans jacket'], 'products' => 2, 'sent_at' => now()->subDay(), 'opened_at' => now()]));
        $this->inShop(fn () => AnalyticsVisit::query()->create(['shop_id' => $this->shop->id, 'session_id' => 'sess-subs-1', 'started_at' => now()->subHours(20), 'last_at' => now()->subHours(20),
            'source' => 'email', 'campaign' => 'search_subscription', 'order_total' => 240]));

        Livewire::test(SearchSubscriptionsSettings::class)
            ->assertSee('הרשמה לחיפוש')
            ->assertSee('חיפושים שאנשים עוקבים אחריהם')
            ->assertSee('4 אנשים עוקבים אחרי')
            ->assertSee('Jeans jacket')
            ->assertSee('אדם אחד עוקב אחרי')
            ->assertSee('מתי יוצא המייל')
            ->assertSet('data.send_hour', 10)
            ->assertSeeHtml('<option value="18">18:00</option>')
            // who signed up, to which search, where it stands, and what waits for their next email
            ->assertSee('מי נרשם')
            ->assertSee('d@example.com')
            ->assertSee('רשום')
            ->assertSee('עדיין אין חדש')
            ->assertSee('מייל אחרון: '.now()->subDay()->format('d.m.Y'))
            ->assertSee('עוד לא קיבל מייל')
            ->set('data.enabled', true)
            ->set('data.send_day', 'sunday')
            ->set('data.reply_to', 'not an address')
            ->call('save')
            ->assertSee('צריך כתובת מייל תקינה');
        $this->assertFalse(Features::enabled('search_subscriptions.enabled', $this->shop->id), 'one wrong value saves nothing');

        Livewire::test(SearchSubscriptionsSettings::class)
            ->set('data.enabled', true)
            ->set('data.send_day', 'sunday')
            ->set('data.send_hour', '24')
            ->set('data.reply_to', 'hello@store.test')
            ->call('save')
            ->assertSee('צריך לבחור שעה מהרשימה');
        $this->assertSame(10, Settings::get('search_subscriptions.send_hour', $this->shop->id), 'an hour off the clock saves nothing');

        Livewire::test(SearchSubscriptionsSettings::class)
            ->set('data.enabled', true)
            ->set('data.send_day', 'sunday')
            ->set('data.send_hour', '7')
            ->set('data.reply_to', 'hello@store.test')
            ->call('save');
        $this->assertTrue(Availability::on($this->shop->id));
        $this->assertSame('sunday', Settings::get('search_subscriptions.send_day', $this->shop->id));
        $this->assertSame(7, Settings::get('search_subscriptions.send_hour', $this->shop->id));
        $this->assertTrue(SendWeekly::due($this->shop->refresh(), Carbon::parse('2026-11-22 05:00', 'UTC')), 'Sunday 07:00 in Israel');
        $this->assertSame('hello@store.test', Settings::get('search_subscriptions.reply_to', $this->shop->id));
        $this->get(SearchSubscriptionsSettings::getUrl())->assertOk();
        $this->assertNotNull($send);

        $numbers = app(FollowedSearches::class)->numbers($this->shop->id, now()->subDays(30), now());
        $this->assertSame(['following' => 4, 'signups' => 5, 'sent' => 1, 'opened' => 1, 'clicked' => 0, 'revenue' => 240.0], $numbers);
    }

    public function test_who_signed_up_shows_what_waits_for_each_ones_next_email_each_search_run_once(): void
    {
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        $search = new class implements ProductsForQuery
        {
            /** @var list<string> */
            public array $asked = [];

            public function find(string $shopId, string $query, int $limit): array
            {
                $this->asked[] = $query;

                return $query === 'Jeans jacket' ? ['j0', 'j1', 'j2', 'j3', 'j4', 'j5'] : [];
            }
        };
        $this->app->instance(ProductsForQuery::class, $search);

        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $this->confirmed($email, 'Jeans jacket', now()->subDays(3));
        }
        $this->confirmed('c@example.com', 'שמלה', now()->subDays(3));
        $this->inShop(fn () => SearchSubscription::query()->create(['shop_id' => $this->shop->id, 'email' => 'p@example.com', 'email_hash' => Subscribers::hash('p@example.com'),
            'query' => 'Jeans jacket', 'query_key' => 'jeans jacket', 'consent_text' => 'yes', 'consent_at' => now(), 'status' => SearchSubscription::PENDING]));
        $this->product('j0', now()->subDays(5));
        foreach (['j1', 'j2', 'j3'] as $id) {
            $this->product($id, now()->subDay());
        }
        $this->product('j4', now()->subDay(), false);
        // a@ already got j1 in last week's email
        $this->inShop(function (): void {
            $send = SearchSubscriptionSend::query()->create(['shop_id' => $this->shop->id, 'email_hash' => Subscribers::hash('a@example.com'), 'kind' => 'weekly', 'queries' => ['Jeans jacket'],
                'products' => 1, 'sent_at' => now()->subHours(20), 'mailed_at' => now()->subHours(20)]);
            SearchSubscriptionProduct::query()->create(['shop_id' => $this->shop->id, 'email_hash' => $send->email_hash, 'send_id' => $send->id,
                'product_id' => CatalogProduct::query()->where('external_id', 'j1')->value('id'), 'sent_at' => now()->subHours(20)]);
        });

        $page = Livewire::test(SearchSubscriptionsSettings::class)
            ->assertSee('2 מוצרים חדשים למייל הבא')
            ->assertSee('3 מוצרים חדשים למייל הבא')
            ->assertSee('עדיין אין חדש')
            ->assertSee('מייל אחרון: '.now()->subHours(20)->format('d.m.Y'))
            ->assertSee('עוד לא קיבל מייל');
        $html = $page->html();
        $this->assertSame(4, substr_count($html, 'data-next-email='), 'active sign-ups only, not one waiting for its confirmation');
        $this->assertSame(['Jeans jacket', 'שמלה'], $search->asked, 'each search once for everyone who follows it');

        Livewire::test(SearchSubscriptionsSettings::class)->assertSee('2 מוצרים חדשים למייל הבא');
        $this->assertCount(2, $search->asked, 'kept until something changes');

        $this->product('j5', now()->subHour());
        Livewire::test(SearchSubscriptionsSettings::class)->assertSee('3 מוצרים חדשים למייל הבא')->assertSee('4 מוצרים חדשים למייל הבא');
        $this->assertCount(4, $search->asked, 'a new product in the store: worked out again');
    }

    private function product(string $id, Carbon $created, bool $inStock = true): void
    {
        $this->inShop(function () use ($id, $created, $inStock): void {
            $p = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => 'Jeans jacket '.$id,
                'url' => "https://store.test/p/{$id}", 'price' => 89, 'currency' => 'USD', 'in_stock' => $inStock, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
            $p->forceFill(['created_at' => $created])->save();
        });
    }

    /** @return array<string, mixed> the drawer's config, as the index gives it */
    private function drawerConfig(): array
    {
        return (array) $this->getJson('/api/v1/search/'.$this->site.'/index', ['Origin' => self::ORIGIN])->assertOk()->json('config');
    }

    private function subscribe(string $email, string $query): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.$this->site.'/subscribe', server: ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'text/plain'], content: json_encode([
            'email' => $email, 'query' => $query, 'consent' => true, 'locale' => 'he', 'consent_text' => 'אני מסכים/ה לקבל מGueta Avigdor מייל.',
        ], JSON_UNESCAPED_UNICODE));
    }

    private function bar(string $path, string $token): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.$this->site.$path, server: ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'text/plain'], content: json_encode(['token' => $token]));
    }

    private function webhook(string $body, ?string $signature = null, ?int $at = null): TestResponse
    {
        $id = 'msg_'.md5($body);
        $at ??= time();
        $signature ??= 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$at.'.'.$body, (string) base64_decode(substr(self::SECRET, 6)), true));

        return $this->call('POST', '/api/v1/search-subscriptions/bounces', server: [
            'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => (string) $at, 'HTTP_SVIX_SIGNATURE' => 'v1,old '.$signature, 'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }

    private function confirmed(string $email, string $query, Carbon $at): SearchSubscription
    {
        return $this->inShop(fn () => SearchSubscription::query()->create([
            'shop_id' => $this->shop->id, 'email' => Subscribers::normalEmail($email), 'email_hash' => Subscribers::hash($email), 'query' => $query,
            'query_key' => Subscribers::queryKey($query), 'consent_text' => 'yes', 'consent_at' => $at, 'status' => SearchSubscription::ACTIVE,
            'confirmed_at' => $at, 'expires_at' => $at->copy()->addDays(90),
        ]));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function inShop(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $fn);
    }
}
