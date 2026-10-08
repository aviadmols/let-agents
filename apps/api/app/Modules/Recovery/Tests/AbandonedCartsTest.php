<?php

namespace App\Modules\Recovery\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\CurrentShop;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Recovery\Actions\ReportAbandonedCarts;
use App\Modules\Recovery\Models\RecoveryCart;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A shopper leaves their email before payment: the store keeps the order, we keep the cart, and
 * an hour later an unpaid cart gets a short report for the shop. Nothing is sent to the shopper.
 */
final class AbandonedCartsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr';

    private const VID = 'anon-cartvisitor1234567890';

    private Shop $shop;

    private string $site;

    private object $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);

        $this->inShop(function (): void {
            $saws = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'c1', 'name' => 'מסורים', 'path' => ['מסורים'], 'depth' => 0, 'hash' => 'c1']);
            foreach ([['10', 'מסור עגול 18V', '890.00'], ['11', 'מסור עגול חשמלי', '540.00'], ['12', 'להב למסור עגול', '79.00']] as [$id, $title, $price]) {
                $product = CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'price' => $price, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$id, 'payload' => [],
                ]);
                if ($id !== '12') {
                    $product->categories()->attach($saws->id);
                }
            }
        });

        $this->chat = new class implements ChatModel
        {
            /** @var list<array{provider: string, user: string}> */
            public array $calls = [];

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls[] = ['provider' => $provider->value, 'user' => $user];

                return $provider === AiProviderName::Anthropic
                    ? new ModelReply([
                        'interest' => 'מסור עגול לעבודות בבית, השווה בין שני דגמים',
                        'searched' => 'מסור עגול',
                        'path' => 'פתח את שני המסורים כמה פעמים והוסיף את היקר לסל',
                        'hesitation' => 'אולי המחיר: ראה דגם זול יותר',
                        'suggestions' => [
                            ['what' => 'להזכיר את מסור עגול 18V ולהציע גם את הדגם הזול', 'refs' => ['P1', 'P2']],
                            ['what' => 'משהו על מוצר שלא קיים', 'refs' => ['P9']],
                        ],
                        'facts' => ['F1', 'F2', 'F99'],
                    ], 400, 120)
                    : new ModelReply(['supported' => true, 'problems' => []], 300, 10);
            }
        };
        $this->app->instance(ChatModel::class, $this->chat);
    }

    public function test_a_cart_from_the_plugin_is_kept_only_with_consent_and_only_when_the_shop_has_it_on(): void
    {
        $this->capture()->assertStatus(202)->assertJson(['stored' => false]);
        $this->assertSame(0, $this->inShop(fn () => RecoveryCart::query()->count()), 'off by default: nothing kept');

        Features::override('recovery.capture', true, $this->shop->id);
        $this->capture()->assertStatus(202)->assertJson(['stored' => true]);
        $this->capture(['items' => [['product_id' => '10', 'quantity' => 2, 'total' => '1780.00']]])->assertStatus(202);

        $cart = $this->inShop(fn () => RecoveryCart::query()->sole());
        $this->assertSame('dana.levi@gmail.com', $cart->email);
        $this->assertSame('d***i@gmail.com', $cart->email_masked);
        $this->assertNotSame('dana.levi@gmail.com', $cart->getRawOriginal('email'), 'the email is encrypted at rest');
        $this->assertSame(2, $cart->items[0]['quantity'], 'the same order captured again updates the same cart');
        $this->assertSame('מסור עגול', $cart->searches[0]['q']);

        $this->capture(['consent' => false])->assertStatus(422)->assertJsonPath('problems', ['consent']);
        $this->capture(['email' => 'not-an-email'])->assertStatus(422);
        $this->capture(sign: false)->assertStatus(401);
    }

    public function test_the_popup_config_follows_the_switch_and_the_shops_words(): void
    {
        $this->getJson("/api/v1/cart/{$this->site}/config")->assertOk()->assertJson(['on' => false]);

        Features::override('recovery.capture', true, $this->shop->id);
        Settings::set('recovery.popup_title', 'רגע, נשמור לכם את הסל', $this->shop->id);

        $this->getJson("/api/v1/cart/{$this->site}/config?locale=he")->assertOk()
            ->assertJson(['on' => true, 'title' => 'רגע, נשמור לכם את הסל', 'button' => __('recovery::storefront.button', [], 'he')]);
        $this->getJson("/api/v1/cart/{$this->site}/config", ['Origin' => 'https://evil.test'])->assertStatus(403);
        $this->get('/api/v1/cart/let-agents-cart.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }

    public function test_an_unpaid_cart_gets_a_short_checked_report_and_a_paid_one_none(): void
    {
        Features::override('recovery.capture', true, $this->shop->id);
        $this->capture()->assertStatus(202);
        $this->capture(['order_ref' => str_repeat('b', 64), 'email' => 'paid@store.test'], vid: 'anon-paidvisitor000000000')->assertStatus(202);

        $this->inShop(function (): void {
            $visitor = hash('sha256', $this->shop->id.'|'.self::VID);
            $n = 0;
            foreach ([['10', 3], ['11', 2]] as [$product, $times]) {
                for ($i = 0; $i < $times; $i++) {
                    AnalyticsEvent::query()->create([
                        'shop_id' => $this->shop->id, 'event_id' => 'e'.++$n, 'type' => 'page_view', 'page_type' => 'product',
                        'page_path' => '/p/'.$product, 'product_external_id' => $product, 'visitor_hash' => $visitor, 'session_id' => 's1',
                        'occurred_at' => now()->subHours(3),
                    ]);
                }
            }
            // The other shopper paid after leaving the email.
            AnalyticsOrder::query()->create([
                'shop_id' => $this->shop->id, 'order_ref' => str_repeat('b', 64), 'total' => 100, 'currency' => 'ILS',
                'items_count' => 1, 'items' => [], 'ordered_at' => now(),
            ]);
            RecoveryCart::query()->update(['captured_at' => now()->subMinutes(90)]);
        });

        $run = app(ReportAbandonedCarts::class)->handle($this->shop->id);
        $this->assertSame('succeeded', $run->status->value, (string) $run->error);

        $carts = $this->inShop(fn () => RecoveryCart::query()->get()->keyBy('email_masked'));
        $this->assertSame(RecoveryCart::CONVERTED, $carts['p***d@store.test']->status, 'paid since: no report, no model');

        $cart = $carts['d***i@gmail.com'];
        $this->assertSame(RecoveryCart::REPORTED, $cart->status);
        $this->assertTrue($cart->report_checked);
        $this->assertSame('מסור עגול לעבודות בבית, השווה בין שני דגמים', $cart->report['interest']);
        $this->assertSame(['להזכיר את מסור עגול 18V ולהציע גם את הדגם הזול'], array_column($cart->report['suggestions'], 'what'), 'a suggestion citing a product it was not given is dropped');
        $this->assertSame(['F1', 'F2'], $cart->report['facts'], 'only facts it was given');

        $this->assertCount(2, $this->chat->calls, 'one writer, one checker');
        $this->assertSame('openai', $this->chat->calls[1]['provider'], 'the other family checks');
        $this->assertStringContainsString('P2 viewed 2 times, not in cart', $this->chat->calls[0]['user']);
        $this->assertStringContainsString('P2 is cheaper than P1', $this->chat->calls[0]['user']);
        $this->assertStringNotContainsString('dana', $this->chat->calls[0]['user'], 'the email never reaches a model');

        app(ReportAbandonedCarts::class)->handle($this->shop->id);
        $this->assertCount(2, $this->chat->calls, 'a cart is reported once');
    }

    public function test_a_cart_with_nothing_to_read_gets_a_code_line_and_no_model(): void
    {
        Features::override('recovery.capture', true, $this->shop->id);
        $this->capture(['searches' => []], vid: '')->assertStatus(202);
        $this->inShop(fn () => RecoveryCart::query()->update(['captured_at' => now()->subHours(2)]));

        app(ReportAbandonedCarts::class)->handle($this->shop->id);

        $cart = $this->inShop(fn () => RecoveryCart::query()->sole());
        $this->assertTrue($cart->report['thin']);
        $this->assertSame([], $this->chat->calls);
    }

    public function test_the_shop_and_the_operator_see_the_carts_and_switch_the_popup(): void
    {
        Features::override('recovery.capture', true, $this->shop->id);
        $this->capture()->assertStatus(202);

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $this->actingAs($merchant)->get("/merchant/{$this->shop->slug}/recovery/carts")
            ->assertOk()
            ->assertSee('dana.levi@gmail.com')
            ->assertSee('מסור עגול 18V')
            ->assertSee(__('recovery::ui.turn_off'));

        $this->actingAs(User::factory()->operator()->create());
        session([CurrentShop::SESSION_KEY => $this->shop->id]);
        $this->get('/operator/recovery/carts')->assertOk()->assertSee('dana.levi@gmail.com');
    }

    /** @param array<string, mixed> $override */
    private function capture(array $override = [], ?string $vid = self::VID, bool $sign = true): TestResponse
    {
        $body = (string) json_encode(array_merge([
            'order_ref' => str_repeat('a', 64), 'order_id' => 501, 'admin_url' => 'https://www.store.test/wp-admin/post.php?post=501&action=edit',
            'email' => 'Dana.Levi@gmail.com', 'consent' => true, 'consent_text' => 'אני מסכים/ה', 'vid' => $vid,
            'items' => [['product_id' => '10', 'quantity' => 1, 'total' => '890.00']], 'total' => '890.00', 'currency' => 'ILS',
            'searches' => [['q' => 'מסור עגול', 'at' => now()->toIso8601String()]], 'captured_at' => now()->toIso8601String(),
        ], $override));
        $path = "/api/v1/plugin/{$this->site}/carts";
        $timestamp = (string) time();

        return $this->call('POST', $path, server: [
            'HTTP_X_LETAGENTS_TIMESTAMP' => $timestamp,
            'HTTP_X_LETAGENTS_SIGNATURE' => $sign ? SiteKeys::signature(self::TOKEN, $timestamp, 'POST', $path, $body) : str_repeat('0', 64),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
