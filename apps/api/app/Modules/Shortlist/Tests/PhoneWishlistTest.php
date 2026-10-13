<?php

namespace App\Modules\Shortlist\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Shortlist\Filament\Merchant\Pages\Wishlist;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Sms\Contracts\Phone;
use App\Modules\Sms\Contracts\SendsSms;
use App\Modules\Sms\Filament\Operator\Pages\SmsSettings;
use App\Modules\Sms\Models\SmsAccount;
use App\Modules\Sms\Models\SmsMessage;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A wishlist kept by phone: a four-digit code by SMS from the platform's 019 account, the list is
 * the number's on every device, and the store manager sees every list, the number in full only
 * when the shopper agreed to hear from the store.
 */
final class PhoneWishlistTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_phone_token_00000000000000000000000000000000000';

    private Shop $shop;

    /** @var list<array<string, mixed>> what 019 was sent */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'רוקס']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN]));
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['1' => 'טבעת יהלום', '2' => 'עגילי זהב', '3' => 'תליון לב'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => 100, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
            }
        });
        Features::override('shortlist.wishlist', true, $this->shop->id);
        Features::override('shortlist.wishlist_phone', true, $this->shop->id);
        SmsAccount::query()->create(['provider' => '019', 'username' => 'letsuser', 'token' => 'secret-019-token', 'sender' => 'LETS', 'active' => true]);
        Http::fake(['019sms.co.il/*' => function (Request $request) {
            $this->sent[] = ['auth' => $request->header('Authorization')[0] ?? null] + $request->data();

            return Http::response(['status' => 0, 'message' => 'SMS will be sent', 'shipment_id' => '777']);
        }]);
    }

    public function test_a_number_is_israeli_mobile_and_masked_when_shown(): void
    {
        $this->assertSame('0501234567', Phone::normalize('050-123-4567'));
        $this->assertSame('0501234567', Phone::normalize('+972 50 123 4567'));
        $this->assertNull(Phone::normalize('03-1234567'), 'a landline gets no code');
        $this->assertNull(Phone::normalize('12345'));
        $this->assertSame('050-•••-4567', Phone::masked('0501234567'));
    }

    public function test_the_list_is_kept_by_phone_on_every_device(): void
    {
        $site = SiteKeys::site(self::TOKEN);
        $this->postJson("/api/v1/shortlist/{$site}/wishlist", ['token' => 'browserAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'add' => ['1', '2']])->assertOk();

        // A code by SMS from LETS, through 019 as its API asks.
        $this->postJson("/api/v1/shortlist/{$site}/phone/code", ['phone' => '050-123-4567'])->assertOk()->assertJson(['sent' => true]);
        $this->assertSame('Bearer secret-019-token', $this->sent[0]['auth']);
        $this->assertSame(['username' => 'letsuser'], $this->sent[0]['sms']['user']);
        $this->assertSame(['LETS', ['phone' => '0501234567']], [$this->sent[0]['sms']['source'], $this->sent[0]['sms']['destinations']]);
        $this->assertMatchesRegularExpression('/רוקס: \d{4}$/u', $this->sent[0]['sms']['message']);
        preg_match('/(\d{4})$/', $this->sent[0]['sms']['message'], $m);
        $this->assertSame(['050-•••-4567', 'wishlist_code', true], [SmsMessage::query()->sole()->phone_masked, SmsMessage::query()->sole()->purpose, SmsMessage::query()->sole()->ok], 'logged, the number masked');

        // A wrong code, then the right one, with a yes to hearing from the store.
        $wrong = $m[1] === '1111' ? '2222' : '1111';
        $this->postJson("/api/v1/shortlist/{$site}/phone/verify", ['phone' => '0501234567', 'code' => $wrong, 'token' => 'browserAAAAAAAAAAAAAAAAAAAAAAAAAAA'])->assertStatus(422)->assertJson(['error' => 'bad_code']);
        $signed = $this->postJson("/api/v1/shortlist/{$site}/phone/verify", ['phone' => '0501234567', 'code' => $m[1], 'token' => 'browserAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'consent' => true])
            ->assertOk()->assertJson(['ok' => true, 'phone' => '050-•••-4567'])->json('session');
        $this->postJson("/api/v1/shortlist/{$site}/phone/verify", ['phone' => '0501234567', 'code' => $m[1]])->assertStatus(422)->assertJson(['error' => 'expired'], 'a code is good once');

        // Another device, another browser: the same list once it is signed in by the same number.
        $this->postJson("/api/v1/shortlist/{$site}/wishlist", ['token' => 'browserBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'add' => ['3'], 'phone' => $signed])
            ->assertOk()->assertJson(['items' => ['3', '1', '2'], 'account' => true, 'phone' => '050-•••-4567']);
        $list = app(TenantContext::class)->run($this->shop->id, fn () => ShortlistWishlist::query()->whereNotNull('phone_verified_at')->sole());
        $this->assertSame(['0501234567', true], [$list->phone, $list->marketing_consent]);
        $this->assertNotSame('0501234567', app(TenantContext::class)->run($this->shop->id, fn () => ShortlistWishlist::query()->toBase()->value('phone')), 'kept encrypted');

        // Signed out: the token names nothing any more.
        $this->postJson("/api/v1/shortlist/{$site}/phone/out", ['phone' => $signed])->assertOk();
        $this->postJson("/api/v1/shortlist/{$site}/wishlist", ['token' => 'browserCCCCCCCCCCCCCCCCCCCCCCCCCCC', 'phone' => $signed])->assertOk()->assertJson(['items' => [], 'account' => false]);

        // The store manager sees the list, the number in full: this shopper agreed.
        $manager = User::factory()->create();
        $manager->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        $page = Livewire::test(Wishlist::class)->assertSee('0501234567')->assertSee('טבעת יהלום');
        $phoneRow = collect($page->instance()->lists())->firstWhere('who', 'phone');
        $this->assertSame(['0501234567', true, 3], [$phoneRow['phone'], $phoneRow['consent'], $phoneRow['count']]);
        $page->call('export')->assertFileDownloaded('wishlists-'.now()->format('Y-m-d').'.csv');
    }

    public function test_without_a_yes_the_manager_sees_the_number_masked_and_codes_are_limited(): void
    {
        $site = SiteKeys::site(self::TOKEN);
        foreach ([1, 2, 3] as $i) {
            $this->postJson("/api/v1/shortlist/{$site}/phone/code", ['phone' => '0529876543'])->assertOk();
        }
        $this->postJson("/api/v1/shortlist/{$site}/phone/code", ['phone' => '0529876543'])->assertStatus(429)->assertJson(['error' => 'too_many']);
        $this->postJson("/api/v1/shortlist/{$site}/phone/code", ['phone' => '03-1234567'])->assertStatus(422)->assertJson(['error' => 'bad_phone']);
        $this->assertCount(3, $this->sent, 'no SMS past the limit, none to a landline');

        preg_match('/(\d{4})$/', $this->sent[2]['sms']['message'], $m);
        $this->postJson("/api/v1/shortlist/{$site}/phone/verify", ['phone' => '0529876543', 'code' => $m[1], 'token' => 'browserDDDDDDDDDDDDDDDDDDDDDDDDDDD'])->assertOk();
        $session = app(TenantContext::class)->run($this->shop->id, fn () => ShortlistWishlist::query()->sole());
        $this->assertFalse($session->marketing_consent, 'never assumed');

        $this->postJson("/api/v1/shortlist/{$site}/wishlist", ['token' => 'browserDDDDDDDDDDDDDDDDDDDDDDDDDDD', 'add' => ['2']])->assertOk();
        $manager = User::factory()->create();
        $manager->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        Livewire::test(Wishlist::class)->assertDontSee('0529876543');
    }

    public function test_the_operator_sets_up_the_019_account_and_sends_a_test(): void
    {
        SmsAccount::query()->delete();
        $this->assertFalse(app(SendsSms::class)->ready());
        $this->getJson('/api/v1/shortlist/'.SiteKeys::site(self::TOKEN).'/config')->assertJsonPath('phoneLogin', false);

        $this->actingAs(User::factory()->operator()->create());
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        Livewire::test(SmsSettings::class)
            ->set('username', 'letsuser')->set('token', 'new-token-1234')->set('sender', 'LETS')->call('save')
            ->set('testPhone', '054-555-1212')->call('test');

        $account = SmsAccount::query()->sole();
        $this->assertSame(['new-token-1234', '…1234'], [$account->token, $account->token_hint]);
        $this->assertNotSame('new-token-1234', SmsAccount::query()->toBase()->value('token'), 'kept encrypted');
        $this->assertSame('0545551212', $this->sent[0]['sms']['destinations']['phone']);
        $this->getJson('/api/v1/shortlist/'.SiteKeys::site(self::TOKEN).'/config')->assertJsonPath('phoneLogin', true);

        Livewire::test(SmsSettings::class)->set('sender', 'Lets Agents Shop')->call('save');
        $this->assertSame('LETS', SmsAccount::query()->sole()->sender, 'a sender of more than 11 letters is refused');
    }
}
