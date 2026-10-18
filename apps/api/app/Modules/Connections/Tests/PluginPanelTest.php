<?php

namespace App\Modules\Connections\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The shop's panel inside wp-admin, as Aviad asked (2026-10-18): the plugin asks with its signed token
 * for a one-time address, the frame opens it, and the store's own account lands in the shop's panel.
 */
final class PluginPanelTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private Shop $shop;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create(['name' => 'גואטה אביגדור', 'platform' => ShopPlatform::WooCommerce]);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://shop.gueta.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
    }

    public function test_the_plugin_opens_the_shops_panel_once_signed_in_as_the_stores_own_account(): void
    {
        $url = $this->ask()->assertOk()->json('url');
        $this->assertStringContainsString('/plugin-login/', $url);

        $opened = $this->get((string) parse_url($url, PHP_URL_PATH));
        $opened->assertRedirect(route('filament.merchant.pages.overview', ['tenant' => $this->shop->slug]));

        $user = auth()->user();
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('store@'.$this->site.'.wordpress', $user->email);
        $this->assertFalse((bool) $user->is_operator);
        $this->assertSame([$this->shop->id], $user->shops()->pluck('shops.id')->all(), 'this shop only');
        $this->assertSame(ShopRole::Owner->value, $user->shops()->first()->pivot->role);
        $this->assertSame('he', $user->locale);

        // The panel answers, and may be framed by the store's own address alone.
        $panel = $this->get(route('filament.merchant.pages.overview', ['tenant' => $this->shop->slug]))->assertOk();
        $csp = (string) $panel->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('https://shop.gueta.test', $csp);
        $this->assertStringNotContainsString('*', $csp);

        // The address is single-use.
        auth()->logout();
        $this->get((string) parse_url($url, PHP_URL_PATH))->assertStatus(410)->assertSee('הקישור כבר נוצל');
        $this->assertNull(auth()->user());
    }

    public function test_a_second_opening_reuses_the_stores_account(): void
    {
        foreach ([1, 2] as $_) {
            $this->get((string) parse_url($this->ask()->json('url'), PHP_URL_PATH))->assertRedirect();
            auth()->logout();
        }

        $this->assertSame(1, User::query()->where('email', 'store@'.$this->site.'.wordpress')->count());
    }

    public function test_an_unsigned_or_wrongly_signed_ask_gets_nothing(): void
    {
        $this->call('POST', "/api/v1/plugin/{$this->site}/panel-login", server: ['CONTENT_TYPE' => 'application/json'], content: '{}')->assertStatus(401);
        $this->ask(signature: str_repeat('0', 64))->assertStatus(401);
        $this->call('POST', '/api/v1/plugin/'.str_repeat('a', 24).'/panel-login', content: '{}')->assertNotFound();
        $this->get('/plugin-login/'.str_repeat('A', 48))->assertStatus(410);
    }

    public function test_the_store_never_signs_in_an_operator_or_another_shops_manager(): void
    {
        // A merchant of another shop with the WordPress admin's own email: never the one signed in.
        $other = Shop::factory()->create();
        $theirs = User::factory()->create(['email' => 'aviad@gueta.test']);
        $theirs->attachShop($other, ShopRole::Owner);

        $this->get((string) parse_url($this->ask(['email' => 'aviad@gueta.test'])->json('url'), PHP_URL_PATH))->assertRedirect();

        $this->assertNotSame($theirs->id, auth()->id());
        $this->assertFalse(auth()->user()->canAccessTenant($other));
    }

    public function test_a_shopify_shops_panel_keeps_its_own_framing_rule(): void
    {
        $shopify = Shop::factory()->create(['platform' => ShopPlatform::Shopify]);
        $owner = User::factory()->create();
        $owner->attachShop($shopify, ShopRole::Owner);

        $csp = (string) $this->actingAs($owner)->get(route('filament.merchant.pages.overview', ['tenant' => $shopify->slug]))->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('gueta', $csp);
    }

    /** @param array<string, string> $body */
    private function ask(array $body = ['locale' => 'he'], ?string $signature = null): TestResponse
    {
        $path = "/api/v1/plugin/{$this->site}/panel-login";
        $content = (string) json_encode($body);
        $timestamp = (string) time();

        return $this->call('POST', $path, server: [
            'HTTP_X_LETAGENTS_TIMESTAMP' => $timestamp,
            'HTTP_X_LETAGENTS_SIGNATURE' => $signature ?? SiteKeys::signature(self::TOKEN, $timestamp, 'POST', $path, $content),
            'CONTENT_TYPE' => 'application/json',
        ], content: $content);
    }
}
