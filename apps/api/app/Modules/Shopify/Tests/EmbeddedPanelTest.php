<?php

namespace App\Modules\Shopify\Tests;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifyPanelHost;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Inside the Shopify admin, Shopify signed the merchant in: the panel offers no "Log out" and no password to change,
 * either of which would leave them (or Shopify's reviewer) on an email and password form inside the admin. The same
 * panel opened on its own keeps both.
 */
final class EmbeddedPanelTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'gueta-test.myshopify.com';

    private const SECRET = 'shpss_test_secret';

    public function test_framed_in_the_shopify_admin_the_panel_has_no_sign_out_and_no_password(): void
    {
        config(['services.shopify.key' => 'test-key', 'services.shopify.secret' => self::SECRET]);
        $shop = Shop::factory()->create(['platform' => ShopPlatform::Shopify]);
        ShopifyInstall::query()->create(['shop_id' => $shop->id, 'shop_domain' => self::SHOP, 'access_token' => 'shpat_test', 'installed_at' => now()]);
        $owner = User::factory()->create();
        $owner->attachShop($shop, ShopRole::Owner);
        $this->actingAs($owner);
        $profile = "/merchant/{$shop->slug}/settings/profile";

        $this->get($profile)->assertOk()->assertSee('data-logout', false)->assertSee('data-change-password', false);

        // the first page Shopify opens (embedded, host) and every frame load after it (Sec-Fetch-Dest)
        foreach ([[$profile.'?embedded=1&host=YWRtaW4&shop='.self::SHOP, []], [$profile, ['Sec-Fetch-Dest' => 'iframe']]] as [$url, $headers]) {
            $this->get($url, $headers)->assertOk()
                ->assertSee('shopify-api-key', false)
                ->assertDontSee('data-logout', false)
                ->assertDontSee('data-change-password', false);
        }
        $this->get("/merchant/{$shop->slug}/overview", ['Sec-Fetch-Dest' => 'iframe'])->assertOk()->assertDontSee('data-logout', false);

        // a request from the frame with a good session token is inside too; a forged one is not
        $host = app(ShopifyPanelHost::class);
        $request = fn (string $token): Request => tap(Request::create($profile), fn (Request $r) => $r->headers->set('Authorization', 'Bearer '.$token));
        $this->assertTrue($host->hosts($request(self::idToken())));
        $this->assertFalse($host->hosts($request(self::idToken([], 'shpss_someone_else'))));
        $this->assertFalse($host->hosts(Request::create($profile)));
    }

    /** @param  array<string, mixed>  $claims */
    private static function idToken(array $claims = [], string $secret = self::SECRET): string
    {
        $claims += ['iss' => 'https://'.self::SHOP.'/admin', 'dest' => 'https://'.self::SHOP, 'aud' => 'test-key', 'sub' => '1', 'exp' => time() + 60, 'nbf' => time() - 5, 'iat' => time() - 5, 'jti' => 'j1', 'sid' => 's1'];
        $encode = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $encode((string) json_encode($claims));

        return $head.'.'.$body.'.'.$encode(hash_hmac('sha256', $head.'.'.$body, $secret, true));
    }
}
