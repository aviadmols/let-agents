<?php

namespace App\Modules\Shoppers\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Shopify\Contracts\KeepsShopperData;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Shoppers\Support\ShopperContactData;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Leaving a way to be told one answer. It is not the shop's sign-up: it has a switch of its own,
 * it carries the question, and it is the team's job list rather than a mailing list.
 */
final class CallbackTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_dddddddddddddddddddddddddddddddddddddddddddddddd';

    private const VID = 'anon-visitor1234567890abcd';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
    }

    public function test_a_question_nobody_answered_can_be_left_with_a_way_to_reach_them(): void
    {
        // The shop's own sign-up is off; this is a different thing and is not blocked by it.
        Features::override('shoppers.signup', false, $this->shop->id);

        $this->leave('0501234567', 'האם יש עוד צבעים למוצר?')->assertOk();

        $callback = app(TenantContext::class)->run($this->shop->id, fn () => ShopperCallback::query()->with('identity')->sole());

        $this->assertSame('האם יש עוד צבעים למוצר?', $callback->question);
        $this->assertSame('product', $callback->page_type);
        $this->assertSame('10', $callback->page_id);
        $this->assertNull($callback->answered_at, 'the team has not come back yet');

        // The contact is kept the way every contact is: encrypted, and shown only masked.
        $this->assertSame('phone', $callback->identity->channel);
        $this->assertStringContainsString('4567', $callback->identity->contact_masked);
        $this->assertStringNotContainsString('0501234567', (string) app(TenantContext::class)->run(
            $this->shop->id,
            fn () => ShopperIdentity::query()->sole()->getRawOriginal('contact'),
        ));
    }

    public function test_from_the_search_chat_a_name_comes_with_the_way_back_and_no_code_to_type(): void
    {
        Mail::fake();

        $response = $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/signup', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['vid' => self::VID, 'contact' => 'dana@example.com', 'name' => 'דנה כהן', 'consent' => true, 'locale' => 'he',
            'question' => 'יש לכם משלוח לאילת?', 'type' => 'search', 'id' => '01m4vd6kdtaj9fmtjyhf99fx1e'], JSON_UNESCAPED_UNICODE));

        $this->assertSame('saved', $response->assertOk()->json('data.status'), 'asked to be called back about one question: nothing to type back');
        Mail::assertNothingSent();
        $callback = app(TenantContext::class)->run($this->shop->id, fn () => ShopperCallback::query()->sole());
        $this->assertSame(['search', '01m4vd6kdtaj9fmtjyhf99fx1e', 'דנה כהן'], [$callback->page_type, $callback->page_id, $callback->name]);
        $this->assertStringNotContainsString('דנה', (string) $callback->getRawOriginal('name'), 'kept encrypted, like the contact');

        // Anything but a name is no name.
        $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/signup', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode(['vid' => self::VID, 'contact' => '0501234567', 'name' => '<b>x</b>', 'consent' => true, 'question' => 'שאלה', 'type' => 'search', 'id' => 'search']))->assertOk();
        $this->assertNull(app(TenantContext::class)->run($this->shop->id, fn () => ShopperCallback::query()->latest('id')->first()->name));
    }

    public function test_it_is_refused_when_the_shop_turned_it_off(): void
    {
        Features::override('shoppers.callbacks', false, $this->shop->id);

        $this->leave('0501234567', 'שאלה')->assertForbidden();
        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn (): int => ShopperCallback::query()->count()));
    }

    public function test_without_a_question_it_is_an_ordinary_sign_up(): void
    {
        Features::override('shoppers.signup', false, $this->shop->id);

        // No question means no callback, so the shop's own sign-up switch decides, and it is off.
        $this->leave('0501234567', '')->assertForbidden();
    }

    public function test_the_team_can_mark_one_as_handled(): void
    {
        $this->leave('0501234567', 'מתי זה חוזר למלאי?')->assertOk();

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $callback = ShopperCallback::query()->sole();
            $callback->update(['answered_at' => now()]);

            $this->assertNotNull($callback->fresh()->answered_at);
        });
    }

    public function test_a_shopper_who_asks_the_store_for_their_data_gets_their_contact_and_their_questions(): void
    {
        $this->leave('Dana@Example.com', 'האם יש עוד צבעים למוצר?')->assertOk();

        $this->assertContains(ShopperContactData::class, array_map(fn (KeepsShopperData $source): string => $source::class, iterator_to_array(app()->tagged(KeepsShopperData::TAG))), 'asked with every module that keeps shopper data');
        $about = fn (string $email): array => app(TenantContext::class)->run($this->shop->id, fn (): array => app(ShopperContactData::class)->about(new ShopperDataRequested($this->shop->id, 'report', $email, '7001')));

        $data = $about('dana@example.com');
        $this->assertSame(['email', 'dana@example.com'], [$data['contact_details'][0]['channel'], $data['contact_details'][0]['contact']], 'as written, not encrypted');
        $this->assertSame(1, $data['contact_details'][0]['browsers_linked']);
        $this->assertSame(['האם יש עוד צבעים למוצר?', 'product 10', null], [$data['callback_requests'][0]['question'], $data['callback_requests'][0]['page'], $data['callback_requests'][0]['answered_at']]);

        $this->assertSame([], $about('noa@example.com'), 'somebody else is told nothing of hers');
    }

    private function leave(string $contact, string $question): TestResponse
    {
        return $this->call('POST', '/api/v1/widget/'.SiteKeys::site(self::TOKEN).'/signup', server: [
            'HTTP_ORIGIN' => 'https://store.test', 'CONTENT_TYPE' => 'text/plain',
        ], content: json_encode([
            'vid' => self::VID,
            'contact' => $contact,
            'consent' => true,
            'locale' => 'he',
            'question' => $question,
            'type' => 'product',
            'id' => '10',
        ], JSON_UNESCAPED_UNICODE));
    }
}
