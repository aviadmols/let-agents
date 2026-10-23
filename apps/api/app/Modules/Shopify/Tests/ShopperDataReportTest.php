<?php

namespace App\Modules\Shopify\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Home;
use App\Modules\Shopify\Contracts\KeepsShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shopify\Mail\ShopperDataRequestMail;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Models\ShopperDataReport;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A shopper asks the store, through Shopify, for what it keeps about them (customers/data_request): every module
 * that keeps shopper data says what, at once; the store's owners are told by email and in their notices, and only
 * they download the file. The same request sent again is one report, and the shopper's request to be erased
 * takes the report too.
 */
final class ShopperDataReportTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'rocks.myshopify.com';

    private const SECRET = 'shpss_privacy_secret';

    private Shop $shop;

    private User $owner;

    /** @var ArrayObject<int, ShopperDataRequested> what the fake module was asked */
    private ArrayObject $asked;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.key' => 'test-key', 'services.shopify.secret' => self::SECRET]);
        Mail::fake();

        $this->shop = Shop::factory()->create(['name' => 'רוקס', 'platform' => ShopPlatform::Shopify, 'domain' => self::SHOP]);
        ShopifyInstall::query()->create(['shop_id' => $this->shop->id, 'shop_domain' => self::SHOP, 'access_token' => 'shpat_test', 'installed_at' => now()]);
        $this->owner = User::factory()->create(['email' => 'owner@rocks.test', 'locale' => 'he']);
        $this->owner->attachShop($this->shop, ShopRole::Owner);

        // A module that keeps something about the shopper, answering inside the shop.
        $this->asked = new ArrayObject;
        $this->app->instance('test.shopper_data', new class($this->asked) implements KeepsShopperData
        {
            /** @param ArrayObject<int, ShopperDataRequested> $asked */
            public function __construct(private ArrayObject $asked) {}

            public function about(ShopperDataRequested $request): array
            {
                $this->asked[] = $request;

                return $request->email === 'dana@example.com' ? ['leads' => [['contact' => 'dana@example.com', 'shop' => app(TenantContext::class)->id()]]] : [];
            }
        });
        $this->app->tag(['test.shopper_data'], KeepsShopperData::TAG);
    }

    public function test_the_shoppers_data_is_gathered_at_once_and_kept_for_the_owners(): void
    {
        $this->webhook('customers/data_request', self::request(9001))->assertOk();

        $report = app(TenantContext::class)->run($this->shop->id, fn () => ShopperDataReport::query()->sole());
        $this->assertSame(['7001', '+972501234567'], [$this->asked[0]->customerId, $this->asked[0]->phone], 'every module is asked, with all Shopify said of the shopper');
        $this->assertSame([['contact' => 'dana@example.com', 'shop' => $this->shop->id]], $report->report['kept']['leads'], 'asked inside the shop');
        $this->assertSame(['shopify_customer_id' => '7001', 'email' => 'dana@example.com', 'phone' => '+972501234567'], $report->report['shopper']);
        $this->assertStringNotContainsString('dana@example.com', (string) $report->getRawOriginal('report'), 'encrypted at rest');

        // The owners are told by email, with nothing about the shopper in it.
        Mail::assertSent(ShopperDataRequestMail::class, fn (ShopperDataRequestMail $mail): bool => $mail->hasTo('owner@rocks.test') && str_contains($mail->link, $report->id));
        Mail::assertSentCount(1);
        $this->assertStringNotContainsString('dana', (new ShopperDataRequestMail('רוקס', now(), 'https://x.test/r'))->render());

        // Shopify sends the same request again: still one report, nobody told twice.
        $this->webhook('customers/data_request', self::request(9001))->assertOk();
        $this->assertSame(1, $this->reports());
        Mail::assertSentCount(1);

        // In the notices, as a thing to do, for everyone in the shop; nothing about the shopper in it.
        app()->setLocale('he');
        $notice = collect(app(Home::class)->notices($this->shop))->firstWhere('key', 'shopify.data_report.'.$report->id);
        $this->assertSame(['todo', 'קונה ביקש את המידע שלו'], [$notice?->kind, $notice?->title]);
        $this->assertStringNotContainsString('dana', (string) $notice?->body);
    }

    public function test_only_the_shops_owners_download_the_file(): void
    {
        $this->webhook('customers/data_request', self::request(9002))->assertOk();
        $report = app(TenantContext::class)->run($this->shop->id, fn () => ShopperDataReport::query()->sole());
        $url = route('shopify.data_report', ['report' => $report->id]);

        $this->get($url)->assertRedirect('/merchant/login'); // signed out (the email's link): the panel's sign-in first, then back here
        $this->assertSame($url, session('url.intended'));

        $member = User::factory()->create();
        $member->attachShop($this->shop, ShopRole::Member);
        $this->actingAs($member)->get($url)->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->get($url)->assertForbidden();

        $otherOwner = User::factory()->create();
        $otherOwner->attachShop(Shop::factory()->create(), ShopRole::Owner);
        $this->actingAs($otherOwner)->get($url)->assertForbidden();

        $file = $this->actingAs($this->owner)->get($url)->assertOk();
        $this->assertStringContainsString('attachment; filename="shopper-data-', (string) $file->headers->get('Content-Disposition'));
        $json = json_decode((string) $file->getContent(), true);
        $this->assertSame(['dana@example.com', 'רוקס'], [$json['shopper']['email'], $json['store']]);
        $this->assertSame('dana@example.com', $json['kept']['leads'][0]['contact']);

        // Taken: the notice says so, and the owner may download it again.
        $report = app(TenantContext::class)->run($this->shop->id, fn () => $report->fresh());
        $this->assertSame($this->owner->id, $report->downloaded_by);
        Home::forget($this->shop);
        $notice = collect(app(Home::class)->notices($this->shop))->firstWhere('key', 'shopify.data_report.'.$report->id);
        $this->assertSame('report', $notice?->kind);
        $this->actingAs($this->owner)->get($url)->assertOk();
    }

    public function test_a_shopper_who_asks_to_be_erased_takes_their_report_too(): void
    {
        $this->webhook('customers/data_request', self::request(9003))->assertOk();
        $this->webhook('customers/data_request', ['customer' => ['id' => 7002, 'email' => 'noa@example.com'], 'data_request' => ['id' => 9004]])->assertOk();
        $this->assertSame(2, $this->reports());

        $this->webhook('customers/redact', ['customer' => ['id' => 7001, 'email' => 'Dana@Example.com']])->assertOk();

        $this->assertSame(1, $this->reports(), 'only the one made for them');
        $this->assertSame('7002', app(TenantContext::class)->run($this->shop->id, fn () => ShopperDataReport::query()->sole()->customer_ref));
    }

    /** @return array<string, mixed> what Shopify sends for customers/data_request */
    private static function request(int $id): array
    {
        return [
            'shop_domain' => self::SHOP,
            'customer' => ['id' => 7001, 'email' => 'Dana@Example.com', 'phone' => '+972501234567'],
            'orders_requested' => [299938, 280263],
            'data_request' => ['id' => $id],
        ];
    }

    private function reports(): int
    {
        return app(TenantContext::class)->run($this->shop->id, fn () => ShopperDataReport::query()->count());
    }

    /** @param array<string, mixed> $payload */
    private function webhook(string $topic, array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/v1/shopify/webhooks', server: [
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }
}
