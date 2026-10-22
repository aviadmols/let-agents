<?php

namespace App\Modules\Shopify\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Inside the Shopify admin the panel's requests carry the session token App Bridge hands it, as Shopify
 * requires of embedded apps (its "session tokens" check): where one comes it is checked, and a bad,
 * expired or another store's token is refused; without one the request goes on by its cookie, as before.
 */
final class SessionTokenTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'gueta-test.myshopify.com';

    private const SECRET = 'shpss_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.key' => 'test-key', 'services.shopify.secret' => self::SECRET]);
        Route::middleware('web')->post('/_test/panel-request', fn () => 'ok');
        Route::middleware('api')->post('/api/_test/key', fn () => 'ok');
    }

    public function test_a_panel_request_inside_shopify_carries_its_session_token_and_a_bad_one_is_refused(): void
    {
        $shop = Shop::factory()->create();
        ShopifyInstall::query()->forceCreate(['shop_id' => $shop->id, 'shop_domain' => self::SHOP, 'installed_at' => now()]);
        $manager = User::factory()->create();
        $manager->attachShop($shop);
        $this->actingAs($manager);

        $this->withToken(self::idToken())->post('/_test/panel-request')->assertOk();
        $this->post('/_test/panel-request')->assertOk();
        $this->withToken(self::idToken([], 'shpss_someone_else'))->post('/_test/panel-request')->assertUnauthorized();
        $this->withToken(self::idToken(['exp' => time() - 120]))->post('/_test/panel-request')->assertUnauthorized();
        $this->withToken('not-a-token')->post('/_test/panel-request')->assertUnauthorized();

        // a good token for a store this person may not open
        $other = Shop::factory()->create();
        ShopifyInstall::query()->forceCreate(['shop_id' => $other->id, 'shop_domain' => 'other-store.myshopify.com', 'installed_at' => now()]);
        $this->withToken(self::idToken(['iss' => 'https://other-store.myshopify.com/admin', 'dest' => 'https://other-store.myshopify.com']))->post('/_test/panel-request')->assertForbidden();

        // the API's own keys are not session tokens
        $this->withToken('lat_site_key')->post('/api/_test/key')->assertOk();
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
