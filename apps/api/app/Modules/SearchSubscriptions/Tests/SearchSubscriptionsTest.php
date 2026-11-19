<?php

namespace App\Modules\SearchSubscriptions\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\SearchSubscriptions\Actions\ForgetRemovedStores;
use App\Modules\SearchSubscriptions\Actions\RemindEnding;
use App\Modules\SearchSubscriptions\Actions\SendWeekly;
use App\Modules\SearchSubscriptions\Jobs\DeliverWeeklyEmails;
use App\Modules\SearchSubscriptions\Mail\ConfirmSubscriptionMail;
use App\Modules\SearchSubscriptions\Mail\StillInterestedMail;
use App\Modules\SearchSubscriptions\Mail\WeeklyNewArrivalsMail;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionProduct;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use App\Modules\SearchSubscriptions\Support\WeeklyEmails;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Search Subscriptions (docs: lets-search-subscriptions): subscribe, confirm (nothing before), the weekly email of new
 * in-stock products only, at most six, never twice, one a week; stopping in one tap; the reminder and keeping; the end.
 */
final class SearchSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private const ORIGIN = 'https://store.test';

    private Shop $shop;

    private string $site;

    /** @var list<string> what the store's search finds for any words, best first */
    private array $found = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->shop = Shop::factory()->create(['name' => 'Gueta Avigdor', 'timezone' => 'Asia/Jerusalem', 'domain' => 'store.test', 'plan' => 'growth']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => self::ORIGIN, 'access_token' => self::TOKEN, 'status' => ConnectionStatus::Connected,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
        Features::override('search_subscriptions.enabled', true, $this->shop->id);
        $test = $this;
        $this->app->instance(ProductsForQuery::class, new class($test) implements ProductsForQuery
        {
            public function __construct(private readonly SearchSubscriptionsTest $test) {}

            public function find(string $shopId, string $query, int $limit): array
            {
                return $this->test->found($query);
            }
        });
    }

    /** @var list<string> the words the store's search was asked, in order */
    private array $asked = [];

    /** @return list<string> */
    public function found(string $query = ''): array
    {
        $this->asked[] = $query;

        return $this->found;
    }

    public function test_a_subscription_waits_for_its_confirmation_and_nothing_is_sent_before(): void
    {
        $this->subscribe('dana@example.com', 'ג׳קט ג׳ינס')->assertOk()->assertJsonPath('status', 'confirm_sent');

        $s = $this->inShop(fn () => SearchSubscription::query()->sole());
        $this->assertSame(SearchSubscription::PENDING, $s->status);
        $this->assertSame('dana@example.com', $s->email);
        $this->assertNotSame('dana@example.com', $s->email_hash, 'the address is kept encrypted, looked up by its hash');
        $this->assertStringContainsString('Gueta Avigdor', $s->consent_text, 'the words agreed to are kept');
        $this->assertNotNull($s->consent_ip_hash);
        Mail::assertSent(ConfirmSubscriptionMail::class, fn (ConfirmSubscriptionMail $m): bool => $m->hasTo('dana@example.com'));

        $this->product('1', 'Jeans jacket', now()->addHour());
        $this->found = ['1'];
        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id), 'not confirmed: nothing');

        $this->get(Subscribers::confirmUrl($s))->assertOk()->assertSee('You’re subscribed');
        $this->assertSame(SearchSubscription::ACTIVE, $s->refresh()->status);
        $this->assertTrue($s->expires_at->between(now()->addDays(89), now()->addDays(91)));
    }

    public function test_no_consent_a_wrong_address_or_personal_details_in_the_search_keep_nothing(): void
    {
        $this->subscribe('dana@example.com', 'ג׳קט', false)->assertStatus(422)->assertJsonPath('status', 'no_consent');
        $this->subscribe('not-an-address', 'ג׳קט')->assertStatus(422)->assertJsonPath('status', 'invalid_email');
        $this->subscribe('dana@example.com', 'call me 050-123-4567')->assertStatus(422)->assertJsonPath('status', 'not_keepable');
        $this->subscribe('dana@example.com', 'mail dana@x.com')->assertStatus(422)->assertJsonPath('status', 'not_keepable');
        $this->assertSame(0, $this->inShop(fn () => SearchSubscription::query()->count()));
        Mail::assertNothingSent();

        Features::override('search_subscriptions.enabled', false, $this->shop->id);
        $this->subscribe('dana@example.com', 'ג׳קט')->assertStatus(403);
    }

    public function test_an_address_keeps_ten_searches_at_most(): void
    {
        Settings::set('search_subscriptions.signups_per_minute', 60);
        foreach (range(1, 10) as $i) {
            $this->subscribe('dana@example.com', 'search '.$i)->assertOk();
        }
        $this->subscribe('dana@example.com', 'search 11')->assertStatus(422)->assertJsonPath('status', 'too_many');
        $this->subscribe('dana@example.com', 'search 3')->assertOk()->assertJsonPath('status', 'confirm_sent');
    }

    public function test_the_weekly_email_holds_only_new_in_stock_products_six_at_most_never_twice_and_once_a_week(): void
    {
        $s = $this->confirmed('dana@example.com', 'jeans jacket', now()->subDays(3));
        $this->product('old', 'Jeans jacket, old', now()->subDays(10));
        $this->product('out', 'Jeans jacket, sold out', now()->subDay(), false);
        foreach (range(1, 8) as $i) {
            $this->product('n'.$i, 'Jeans jacket '.$i, now()->subDay());
        }
        $this->found = ['old', 'out', 'n1', 'n2', 'n3', 'n4', 'n5', 'n6', 'n7', 'n8'];

        $this->assertSame(1, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        Mail::assertSent(WeeklyNewArrivalsMail::class, function (WeeklyNewArrivalsMail $m): bool {
            $titles = array_column($m->searches[0]['products'], 'title');
            $headers = $m->headers()->text;

            return $m->hasTo('dana@example.com') && $titles === ['Jeans jacket 1', 'Jeans jacket 2', 'Jeans jacket 3', 'Jeans jacket 4', 'Jeans jacket 5', 'Jeans jacket 6']
                && str_contains($headers['List-Unsubscribe'] ?? '', '/unsubscribe') && ($headers['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click'
                && $m->envelope()->subject === '6 new for “jeans jacket” · Gueta Avigdor' && $m->envelope()->from->name === 'Gueta Avigdor';
        });

        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id), 'one a week');
        $this->travel(7)->days();
        Mail::fake();
        $this->assertSame(1, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        Mail::assertSent(WeeklyNewArrivalsMail::class, fn (WeeklyNewArrivalsMail $m): bool => array_column($m->searches[0]['products'], 'title') === ['Jeans jacket 7', 'Jeans jacket 8']);

        $this->travel(7)->days();
        Mail::fake();
        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id), 'nothing new: no email');
        Mail::assertNothingSent();
        $this->assertSame(8, $this->inShop(fn () => SearchSubscriptionProduct::query()->where('subscription_id', $s->id)->count()));
    }

    public function test_one_address_gets_one_email_for_all_its_searches_each_with_its_best(): void
    {
        $this->confirmed('dana@example.com', 'jeans jacket', now()->subDays(3));
        $this->confirmed('dana@example.com', 'white sneakers', now()->subDays(3));
        foreach (range(1, 6) as $i) {
            $this->product('p'.$i, 'Product '.$i, now()->subDay());
        }
        $this->found = ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'];

        $this->assertSame(1, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        Mail::assertSent(WeeklyNewArrivalsMail::class, 1);
        Mail::assertSent(WeeklyNewArrivalsMail::class, fn (WeeklyNewArrivalsMail $m): bool => count($m->searches) === 2 && $m->total() === 6
            && array_column($m->searches[0]['products'], 'title') === ['Product 1', 'Product 3', 'Product 5'], 'in turn, never one product under two searches');
    }

    public function test_the_weekly_email_goes_on_the_stores_day_and_hour_in_its_own_time(): void
    {
        $this->assertTrue(SendWeekly::due($this->shop, Carbon::parse('2026-11-19 08:30', 'UTC')), 'Thursday 10:30 in Israel');
        $this->assertFalse(SendWeekly::due($this->shop, Carbon::parse('2026-11-19 10:30', 'UTC')));
        $this->assertFalse(SendWeekly::due($this->shop, Carbon::parse('2026-11-18 08:30', 'UTC')));
    }

    public function test_a_store_whose_owner_chose_another_day_and_hour_gets_its_email_then_and_only_then(): void
    {
        Settings::set('search_subscriptions.send_day', 'monday', $this->shop->id);
        Settings::set('search_subscriptions.send_hour', 18, $this->shop->id);
        $this->confirmed('dana@example.com', 'jeans jacket', Carbon::parse('2026-11-10 09:00', 'UTC'));
        $this->product('n', 'Jeans jacket new', Carbon::parse('2026-11-15 09:00', 'UTC'));
        $this->found = ['n'];

        // Monday 23.11 in Israel (UTC+2): 10:00 there is 08:00 UTC, 18:00 there is 16:00 UTC
        $this->travelTo(Carbon::parse('2026-11-23 08:02', 'UTC'));
        $this->assertSame(0, app(SendWeekly::class)->dispatchDue(), 'not at the default hour');
        $this->travelTo(Carbon::parse('2026-11-19 16:02', 'UTC'));
        $this->assertSame(0, app(SendWeekly::class)->dispatchDue(), 'not on the default day at its hour');
        Mail::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-11-23 16:02', 'UTC'));
        $this->assertSame(1, app(SendWeekly::class)->dispatchDue());
        Mail::assertSent(WeeklyNewArrivalsMail::class, fn (WeeklyNewArrivalsMail $m): bool => $m->hasTo('dana@example.com'));
        $this->assertSame('2026-W48', $this->inShop(fn () => SearchSubscriptionSend::query()->sole()->period));

        $this->travelTo(Carbon::parse('2026-11-23 17:02', 'UTC'));
        $this->assertSame(0, app(SendWeekly::class)->dispatchDue(), 'its hour has passed');
        Mail::assertSent(WeeklyNewArrivalsMail::class, 1);
    }

    public function test_thousands_of_subscriptions_take_bounded_queries_each_search_once_and_one_email_an_address(): void
    {
        // 2,000 subscriptions over 50 searches: 1,500 addresses, 500 of them following a second search
        $this->manySubscriptions(2000, 50, 1500, now()->subDays(10));
        foreach (range(1, 20) as $i) {
            $this->product('p'.$i, 'Product '.$i, now()->subDays(2));
        }
        $this->found = array_map(fn (int $i): string => 'p'.$i, range(1, 20));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $memory = memory_get_usage();
        $this->assertSame(1500, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $memory);

        $this->assertLessThan(200, $queries, 'queries by slices of addresses, never by subscriber or product: '.$queries);
        $this->assertCount(50, $this->asked, 'each distinct search once');
        $to = collect(Mail::sent(WeeklyNewArrivalsMail::class))->map(fn (WeeklyNewArrivalsMail $m): string => $m->to[0]['address']);
        $this->assertCount(1500, $to);
        $this->assertCount(1500, $to->unique(), 'one email an address');
        $this->assertSame(1500, $this->inShop(fn () => SearchSubscriptionSend::query()->whereNotNull('mailed_at')->count()));
        Mail::assertSent(WeeklyNewArrivalsMail::class, fn (WeeklyNewArrivalsMail $m): bool => $m->total() === 6 && count($m->searches) <= 2);

        // the hour again, or a job retried: nothing twice
        Mail::fake();
        $this->asked = [];
        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        Mail::assertNothingSent();
    }

    public function test_nothing_new_in_the_store_runs_no_search_at_all(): void
    {
        $this->manySubscriptions(300, 30, 300, now()->subDays(3));
        $this->product('old', 'Product old', now()->subDays(5));
        $this->found = ['old'];

        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        $this->assertSame([], $this->asked);
        Mail::assertNothingSent();
    }

    public function test_the_database_holds_one_weekly_email_an_address_a_week_even_when_a_run_repeats(): void
    {
        $s = $this->confirmed('dana@example.com', 'jeans jacket', now()->subDays(20));
        $this->product('n', 'Jeans jacket new', now()->subDay());
        $this->found = ['n'];
        // this week's email of the address already written, though its time reads as long ago (as a race would leave it)
        $this->inShop(fn () => SearchSubscriptionSend::query()->create(['shop_id' => $this->shop->id, 'email_hash' => $s->email_hash, 'kind' => 'weekly',
            'period' => SendWeekly::period($this->shop, now()), 'queries' => ['jeans jacket'], 'products' => 1, 'sent_at' => now()->subDays(8), 'mailed_at' => now()->subDays(8), 'opened_at' => now()->subDays(8)]));

        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        Mail::assertNothingSent();
        $this->assertSame(0, $this->inShop(fn () => SearchSubscriptionProduct::query()->count()), 'its products wait for an email that goes');
    }

    public function test_through_resend_the_emails_go_a_hundred_a_request_once_and_a_refused_one_is_taken_back(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => 're_test_key']);
        Mail::swap(new MailManager($this->app));
        Settings::set('search_subscriptions.mail_requests_per_second', 10);
        $requests = 0;
        Http::fake(['api.resend.com/*' => function (Request $request) use (&$requests) {
            $requests++;

            return Http::response(['data' => array_map(fn (int $i): array => ['id' => 'em_'.$i], range(1, count($request->data())))]
                + ($requests === 2 ? ['errors' => [['index' => 0, 'message' => 'Invalid `to` field.']]] : []));
        }]);
        $this->assertTrue(WeeklyEmails::batched());
        $this->manySubscriptions(150, 5, 150, now()->subDays(4));
        $this->product('n', 'Jeans jacket new', now()->subDay());
        $this->found = ['n'];

        $this->assertSame(150, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        Http::assertSentCount(2);
        $sizes = [];
        Http::assertSent(function (Request $r) use (&$sizes): bool {
            $sizes[] = count($r->data());
            $first = $r->data()[0] ?? [];

            return $r->url() === 'https://api.resend.com/emails/batch' && $r->hasHeader('Authorization', 'Bearer re_test_key')
                && str_starts_with($r->header('Idempotency-Key')[0] ?? '', 'search-subscriptions-weekly-') && $r->hasHeader('x-batch-validation', 'permissive')
                && $first['from'] === '"Gueta Avigdor" <'.config('mail.from.address').'>' && str_contains($first['html'], 'Jeans jacket new')
                && ($first['headers']['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click' && str_starts_with($first['subject'], '1 new for “search ');
        });
        sort($sizes);
        $this->assertSame([50, 100], $sizes);
        $this->assertSame(149, $this->inShop(fn () => SearchSubscriptionSend::query()->whereNotNull('mailed_at')->count()));
        $this->assertSame(149, $this->inShop(fn () => SearchSubscriptionSend::query()->count()), 'the refused one is taken back');
        $this->assertSame(149, $this->inShop(fn () => SearchSubscriptionProduct::query()->count()), 'with its product, for next time');

        // a delivery run again after the fact mails nothing more
        $ids = $this->inShop(fn () => SearchSubscriptionSend::query()->pluck('id')->all());
        DeliverWeeklyEmails::dispatchSync($this->shop->id, array_slice($ids, 0, 100));
        Http::assertSentCount(2);
    }

    public function test_one_tap_stops_one_search_or_all_of_them_and_a_mail_clients_button_does_too(): void
    {
        $a = $this->confirmed('dana@example.com', 'jeans jacket', now()->subDay());
        $b = $this->confirmed('dana@example.com', 'white sneakers', now()->subDay());

        $this->get(Subscribers::stopUrl($a))->assertOk()->assertSee('Stopped');
        $this->assertSame(['stopped', 'active'], [$a->refresh()->status, $b->refresh()->status]);

        $this->get(Subscribers::manageUrl($this->shop->id, $b->email_hash))->assertOk()->assertSee('white sneakers')->assertDontSee('jeans jacket');
        $this->post(Subscribers::oneClickUrl($this->shop->id, $b->email_hash))->assertOk();
        $this->assertSame('stopped', $b->refresh()->status);

        $this->get(str_replace('signature=', 'signature=x', Subscribers::stopUrl($b)))->assertForbidden();
    }

    public function test_three_unopened_emails_stop_an_address_and_a_click_counts_as_opened(): void
    {
        $s = $this->confirmed('dana@example.com', 'jeans jacket', now()->subDays(30));
        foreach ([3, 2, 1] as $weeks) {
            $this->inShop(fn () => SearchSubscriptionSend::query()->create(['shop_id' => $this->shop->id, 'email_hash' => $s->email_hash, 'queries' => ['jeans jacket'], 'products' => 1, 'sent_at' => now()->subWeeks($weeks)]));
        }
        $this->product('n', 'Jeans jacket new', now()->subDay());
        $this->found = ['n'];
        $last = $this->inShop(fn () => SearchSubscriptionSend::query()->latest('sent_at')->first());
        $this->get(Subscribers::clickUrl($last->id, 'https://store.test/p/x'))->assertRedirect('https://store.test/p/x');

        $this->assertSame(1, app(SendWeekly::class)->handle(onlyShop: $this->shop->id), 'clicked: still wanted');

        $this->inShop(fn () => SearchSubscriptionSend::query()->update(['opened_at' => null, 'clicked_at' => null]));
        $this->travel(7)->days();
        $this->product('m', 'Jeans jacket newer', now()->subDay());
        $this->found = ['m'];
        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id));
        $this->assertSame(['stopped', 'unopened'], [$s->refresh()->status, $s->stop_reason]);
    }

    public function test_a_subscription_is_reminded_before_it_ends_kept_by_one_tap_and_ends_by_itself_otherwise(): void
    {
        $s = $this->confirmed('dana@example.com', 'jeans jacket', now()->subDays(85));
        $this->inShop(fn () => $s->forceFill(['expires_at' => now()->addDays(5)])->save());

        $this->assertSame(1, app(RemindEnding::class)->handle());
        Mail::assertSent(StillInterestedMail::class, fn (StillInterestedMail $m): bool => $m->envelope()->subject === 'Still interested in “jeans jacket”?');
        $this->assertSame(0, app(RemindEnding::class)->handle(), 'once');

        $this->get(Subscribers::keepUrl($s))->assertOk()->assertSee('Kept');
        $this->assertTrue($s->refresh()->expires_at->gt(now()->addDays(90)));

        $this->inShop(fn () => $s->forceFill(['expires_at' => now()->subMinute()])->save());
        $this->found = [];
        app(SendWeekly::class)->handle(onlyShop: $this->shop->id);
        $this->assertSame(['stopped', 'expired'], [$s->refresh()->status, $s->stop_reason]);
    }

    public function test_an_erasure_removes_the_address_from_every_store_and_a_removed_store_is_forgotten(): void
    {
        $s = $this->confirmed('Dana@Example.com', 'jeans jacket', now()->subDay());
        $other = Shop::factory()->create();
        app(TenantContext::class)->run($other->id, fn () => SearchSubscription::query()->create(['shop_id' => $other->id, 'email' => 'dana@example.com',
            'email_hash' => Subscribers::hash('dana@example.com'), 'query' => 'x', 'query_key' => 'x', 'consent_text' => 'x', 'consent_at' => now(), 'status' => 'active']));

        event(new ShopperDataRequested($this->shop->id, 'redact', 'dana@example.com'));
        $this->assertSame(0, app(TenantContext::class)->runUnscoped(fn () => SearchSubscription::query()->count()));

        $this->confirmed('ron@example.com', 'jeans jacket', now()->subDay());
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $this->shop->id)->update(['status' => ConnectionStatus::Failed]));
        $this->product('n', 'Jeans jacket new', now()->subHour());
        $this->found = ['n'];
        $this->assertSame(0, app(SendWeekly::class)->handle(onlyShop: $this->shop->id), 'a store that removed Let’s gets nothing sent');
        $this->assertSame(0, app(ForgetRemovedStores::class)->handle(), 'not yet');
        $this->travel(31)->days();
        $this->assertSame(1, app(ForgetRemovedStores::class)->handle());
        $this->assertSame(0, app(TenantContext::class)->runUnscoped(fn () => SearchSubscription::query()->count()));
        $this->assertNotNull($s);
    }

    public function test_long_searches_and_names_are_cut_and_emails_stay_left_to_right(): void
    {
        $this->assertSame('אני מחפשת שמלה ארוכה מאוד…', Subscribers::cut('אני מחפשת שמלה ארוכה מאוד לחתונה של אחותי', 30));
        $s = $this->confirmed('dana@example.com', 'ג׳קט ג׳ינס', now()->subDays(2));
        $this->product('n', str_repeat('Long product name ', 8), now()->subDay());
        $this->found = ['n'];
        app(SendWeekly::class)->handle(onlyShop: $this->shop->id);
        Mail::assertSent(WeeklyNewArrivalsMail::class, function (WeeklyNewArrivalsMail $m): bool {
            $html = $m->render();

            return mb_strlen($m->searches[0]['products'][0]['title']) <= 60 && ! str_contains($html, 'dir="rtl"') && ! str_contains($html, 'text-align: right')
                && str_contains($html, 'ג׳קט ג׳ינס') && str_contains($html, 'utm_campaign%3Dsearch_subscription');
        });
        $this->assertNotNull($s);
    }

    private function subscribe(string $email, string $query, bool $consent = true): TestResponse
    {
        return $this->call('POST', '/api/v1/search/'.$this->site.'/subscribe', server: ['HTTP_ORIGIN' => self::ORIGIN, 'CONTENT_TYPE' => 'text/plain'], content: json_encode([
            'email' => $email, 'query' => $query, 'consent' => $consent, 'locale' => 'he',
            'consent_text' => 'אני מסכים/ה לקבל מGueta Avigdor מייל עם מוצרים חדשים שמתאימים לחיפוש הזה. אפשר לבטל בכל רגע.',
        ], JSON_UNESCAPED_UNICODE));
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
     * $count active subscriptions to $searches searches ("search 0", …) from $addresses addresses: each address its first
     * search, and the first ones a second, written in bulk so the test stays fast.
     */
    private function manySubscriptions(int $count, int $searches, int $addresses, Carbon $confirmed): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $address = $i < $addresses ? $i : $i - $addresses;
            $search = $i < $addresses ? $i % $searches : ($i + 7) % $searches;
            $email = "shopper{$address}@example.com";
            $rows[] = ['id' => strtolower((string) Str::ulid()), 'shop_id' => $this->shop->id, 'email' => Crypt::encryptString($email), 'email_hash' => Subscribers::hash($email),
                'query' => "search {$search}", 'query_key' => "search {$search}", 'locale' => 'he', 'status' => SearchSubscription::ACTIVE, 'consent_text' => 'yes',
                'consent_at' => $confirmed, 'confirmed_at' => $confirmed, 'expires_at' => $confirmed->copy()->addDays(90), 'created_at' => $confirmed, 'updated_at' => $confirmed];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('search_subscriptions')->insert($chunk);
        }
    }

    private function product(string $id, string $title, Carbon $created, bool $inStock = true): void
    {
        $this->inShop(function () use ($id, $title, $created, $inStock): void {
            $p = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'url' => "https://store.test/p/{$id}", 'price' => 89, 'currency' => 'USD', 'in_stock' => $inStock, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
            $p->forceFill(['created_at' => $created])->save();
        });
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
