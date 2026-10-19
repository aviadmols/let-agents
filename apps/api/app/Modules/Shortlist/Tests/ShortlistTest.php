<?php

namespace App\Modules\Shortlist\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shortlist\Actions\ProductCards;
use App\Modules\Shortlist\Http\Controllers\ShortlistScriptController;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compare and wishlist, from the storefront's side: off until the shop turns them on; the table
 * from approved facts, in code; a guest's list in their browser, an account's wherever they sign
 * in (Shopify vouches through its app proxy, the WordPress plugin signs), shared by a code.
 */
final class ShortlistTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_shortlist_token_000000000000000000000000000000';

    private const SECRET = 'shpss_shortlist_secret';

    private const BROWSER = 'browserTokenAAAAAAAAAAAAAAAAAAAAAA';

    private Shop $shop;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $vocabulary = EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'jewelry', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'jewelry', 'name' => ['he' => 'תכשיטים', 'en' => 'Jewelry'], 'root_category_external_id' => '*',
                    'product_types' => [['key' => 'ring', 'label' => ['he' => 'טבעת', 'en' => 'Ring']]],
                    'attributes' => [
                        ['key' => 'metal', 'type' => 'enum', 'label' => ['he' => 'מתכת', 'en' => 'Metal'], 'values' => [
                            ['key' => 'yellow_gold', 'label' => ['he' => 'זהב צהוב', 'en' => 'Yellow gold']], ['key' => 'white_gold', 'label' => ['he' => 'זהב לבן', 'en' => 'White gold']]]],
                        ['key' => 'width_mm', 'type' => 'number', 'unit' => 'mm', 'label' => ['he' => 'רוחב', 'en' => 'Width']],
                    ]]]);
            foreach (['11' => ['yellow_gold', 2.0], '12' => ['white_gold', 2.0], '13' => [null, null]] as $id => [$metal, $width]) {
                $id = (string) $id;
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "טבעת {$id}",
                    'url' => "https://store.test/products/ring-{$id}/", 'image_url' => "https://store.test/{$id}.jpg", 'price' => 1000 + (int) $id, 'currency' => 'ILS', 'in_stock' => $id !== '13', 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                $facts = [[FactKind::Type, 'type', 'ring', null, null]];
                if ($metal !== null) {
                    $facts[] = [FactKind::Choice, 'metal', $metal, null, null];
                    $facts[] = [FactKind::Spec, 'width_mm', null, $width, 'mm'];
                }
                foreach ($facts as $i => [$kind, $key, $text, $number, $unit]) {
                    EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => $kind, 'key' => $key,
                        'value_text' => $text, 'value_number' => $number, 'unit' => $unit, 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "f{$id}{$i}"]);
                }
            }
            // A product the store removed is never compared or listed.
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '14', 'type' => 'simple', 'status' => 'publish', 'title' => 'הוסר',
                'url' => 'https://store.test/products/gone', 'price' => 5, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h14', 'payload' => [], 'removed_at' => now()]);
        });
    }

    public function test_both_are_off_until_the_shop_turns_them_on(): void
    {
        $this->getJson("/api/v1/shortlist/{$this->site}/config")->assertOk()->assertExactJson(['compare' => false, 'wishlist' => false]);
        $this->getJson("/api/v1/shortlist/{$this->site}/compare?ids=11,12")->assertNotFound();
        $this->postJson("/api/v1/shortlist/{$this->site}/wishlist", ['token' => self::BROWSER, 'add' => ['11']])->assertNotFound();
        $this->assertSame(0, ShortlistWishlist::query()->withoutGlobalScopes()->count());

        Features::override('shortlist.compare', true, $this->shop->id);
        $config = $this->getJson("/api/v1/shortlist/{$this->site}/config?locale=he")->assertOk()->json();
        // What the search drawer reads, and nothing of the old buttons' placement.
        $this->assertSame(['compare', 'wishlist', 'compareMax', 'wishlistWhatsapp', 'phoneLogin', 'heartProduct', 'heartCards', 'heartPlace', 'heartResults', 'heartClick', 'cardSelector', 'shopName', 'labels'], array_keys($config));
        $this->assertSame([true, false, 5, 'Rocks and Gold', false], [$config['compare'], $config['wishlist'], $config['compareMax'], $config['shopName'], $config['phoneLogin']]);
        $this->assertSame([false, false, 'corner'], [$config['heartProduct'], $config['heartCards'], $config['heartPlace']], 'no hearts on the store\'s pages while the wishlist is off');

        Features::override('shortlist.wishlist', true, $this->shop->id);
        $config = $this->getJson("/api/v1/shortlist/{$this->site}/config?locale=he")->assertOk()->json();
        $this->assertSame([true, true, 'corner'], [$config['heartProduct'], $config['heartCards'], $config['heartPlace']], 'the hearts come with the wishlist');
        $this->assertSame([true, 'quiet', ''], [$config['heartResults'], $config['heartClick'], $config['cardSelector']], 'a heart in the results, saved quietly, the known cards only');
        $this->assertSame('רשימת המשאלות שלי ב:shop', $config['labels']['share_text']);
        $this->assertSame('התנתקות', $config['labels']['phone_out']);
        $this->assertArrayHasKey('phone_consent', $config['labels']);
        $this->assertArrayNotHasKey('phone_sms', $config['labels'], 'the SMS text is the server\'s');
        $this->getJson("/api/v1/shortlist/{$this->site}/config", ['Origin' => 'https://evil.test'])->assertForbidden();
        $this->getJson("/api/v1/shortlist/{$this->site}/paths")->assertNotFound();
    }

    public function test_the_old_buttons_script_answers_for_old_plugins_and_does_nothing(): void
    {
        $response = $this->get('/api/v1/shortlist/let-agents-shortlist.js')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(ShortlistScriptController::SCRIPT, $response->getContent());
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertLessThan(200, strlen((string) $response->getContent()));
        $this->get('/api/v1/shortlist/let-agents-shortlist.js', ['If-None-Match' => (string) $response->headers->get('ETag')])->assertStatus(304);
    }

    public function test_the_table_is_the_products_approved_facts_with_where_they_differ(): void
    {
        Features::override('shortlist.compare', true, $this->shop->id);

        $table = $this->getJson("/api/v1/shortlist/{$this->site}/compare?locale=he&ids=12,11,14,13,12")->assertOk()->json();

        $this->assertSame(['12', '11', '13'], array_column($table['products'], 'id'), 'in the order asked, once each, without the removed one');
        $this->assertEquals([1012, false], [$table['products'][0]['price'], $table['products'][2]['in_stock']]);
        $rows = collect($table['rows'])->keyBy('label');
        $this->assertSame(['סוג מוצר', 'מתכת', 'רוחב'], $rows->keys()->all(), 'what it is, then its choices, then its measures');
        $this->assertSame(['טבעת', 'טבעת', 'טבעת'], $rows['סוג מוצר']['values']);
        $this->assertFalse($rows['סוג מוצר']['differs']);
        $this->assertSame(['זהב לבן', 'זהב צהוב', null], $rows['מתכת']['values'], 'a product without the fact has none');
        $this->assertTrue($rows['מתכת']['differs']);
        $this->assertSame('2 mm', $rows['רוחב']['values'][0]);

        $this->assertSame('Metal', collect($this->getJson("/api/v1/shortlist/{$this->site}/compare?locale=en&ids=11,12")->json('rows'))->firstWhere('key', 'choice:metal')['label']);
        $this->assertSame(['11', '12', '13', '4', '5'], ProductCards::ids('11, 12,12,13,4,5,6,a b', 5), 'at most five, once each; nothing that is not an id');
    }

    public function test_a_guests_list_is_their_browsers_and_joins_their_account_when_they_sign_in(): void
    {
        Features::override('shortlist.wishlist', true, $this->shop->id);
        $url = "/api/v1/shortlist/{$this->site}/wishlist";

        $this->postJson($url, ['token' => self::BROWSER])->assertOk()->assertJson(['items' => [], 'share' => null]);
        $this->assertSame(0, ShortlistWishlist::query()->withoutGlobalScopes()->count(), 'asking for an empty list keeps nothing');

        $this->postJson($url, ['token' => self::BROWSER, 'add' => ['11']])->assertOk();
        $list = $this->postJson($url, ['token' => self::BROWSER, 'add' => ['12', '14']])->assertOk()->json();
        $this->assertSame(['12', '14', '11'], $list['items'], 'newest first');
        $this->assertSame(['12', '11'], array_column($list['products'], 'id'), 'a removed product is not shown');
        $this->assertSame(['11'], $this->postJson($url, ['token' => self::BROWSER, 'remove' => ['12', '14']])->json('items'));
        $this->assertSame([], $this->postJson($url, ['token' => 'anotherBrowserBBBBBBBBBBBBBBBBBBBB'])->json('items'), 'another browser has its own');
        $this->assertNull(ShortlistWishlist::query()->withoutGlobalScopes()->value('customer_ref'));
        $this->assertSame(hash('sha256', self::BROWSER), ShortlistWishlist::query()->withoutGlobalScopes()->value('token_hash'), 'the token itself is never kept');

        // The same shopper, signed in on another device, already saved one: both lists join, the browser's first.
        $proof = fn (string $user) => $user.'.'.time().'.'.SiteKeys::customer(self::TOKEN, $user, (string) time());
        $this->postJson($url, ['token' => 'phoneTokenCCCCCCCCCCCCCCCCCCCCCCCCCC', 'customer' => $proof('42'), 'add' => ['13']])->assertOk();
        $joined = $this->postJson($url, ['token' => self::BROWSER, 'customer' => $proof('42')])->assertOk()->json();
        $this->assertSame([['11', '13'], true], [$joined['items'], $joined['account']]);
        $this->assertSame(1, ShortlistWishlist::query()->withoutGlobalScopes()->count(), 'one list: the account\'s');
        $this->assertSame('wordpress:42', ShortlistWishlist::query()->withoutGlobalScopes()->value('customer_ref'));

        // A forged or stale proof is a guest's request, and sees no account.
        $this->assertSame([], $this->postJson($url, ['token' => 'strangerDDDDDDDDDDDDDDDDDDDDDDDDDD', 'customer' => '42.'.time().'.'.str_repeat('0', 64)])->json('items'));
        $old = (string) (time() - 3 * 86400);
        $this->assertSame([], $this->postJson($url, ['token' => 'strangerDDDDDDDDDDDDDDDDDDDDDDDDDD', 'customer' => '42.'.$old.'.'.SiteKeys::customer(self::TOKEN, '42', $old)])->json('items'));

        // Shared: anyone with the code sees the products, read-only.
        $shared = $this->getJson("/api/v1/shortlist/{$this->site}/shared/{$joined['share']}")->assertOk()->json('products');
        $this->assertSame(['11', '13'], array_column($shared, 'id'));
        $this->getJson("/api/v1/shortlist/{$this->site}/shared/nothing0here")->assertNotFound();
    }

    public function test_through_shopifys_app_proxy_the_customer_is_the_one_shopify_signed(): void
    {
        config(['services.shopify.key' => 'test-key', 'services.shopify.secret' => self::SECRET]);
        Features::override('shortlist.wishlist', true, $this->shop->id);
        ShopifyInstall::query()->create(['shop_id' => $this->shop->id, 'shop_domain' => 'rocks.myshopify.com', 'access_token' => 'shpat_test', 'installed_at' => now()]);

        $signed = function (array $query): string {
            $query += ['shop' => 'rocks.myshopify.com', 'path_prefix' => '/apps/letagents', 'timestamp' => (string) time()];
            ksort($query);
            $query['signature'] = hash_hmac('sha256', implode('', array_map(fn ($k, $v) => $k.'='.$v, array_keys($query), $query)), self::SECRET);

            return '/api/v1/shortlist/proxy/wishlist?'.http_build_query($query);
        };

        $this->postJson($signed(['logged_in_customer_id' => '7001']), ['token' => self::BROWSER, 'add' => ['12']])->assertOk()->assertJson(['items' => ['12'], 'account' => true]);
        $this->assertSame('shopify:7001', ShortlistWishlist::query()->withoutGlobalScopes()->value('customer_ref'));
        $this->assertSame(['12'], $this->postJson($signed(['logged_in_customer_id' => '7001']), ['token' => 'otherDeviceEEEEEEEEEEEEEEEEEEEEEEE'])->json('items'), 'the account\'s list, on any device');

        // Claiming a customer in the address without Shopify's signature opens nothing.
        $forged = '/api/v1/shortlist/proxy/wishlist?'.http_build_query(['shop' => 'rocks.myshopify.com', 'logged_in_customer_id' => '7001', 'timestamp' => (string) time(), 'signature' => str_repeat('a', 64)]);
        $this->postJson($forged, ['token' => self::BROWSER])->assertUnauthorized();

        // The customer asks the store to forget them: their account's list goes, no one else's.
        event(new ShopperDataRequested($this->shop->id, 'report', 'a@b.test', '7001'));
        $this->assertSame(1, ShortlistWishlist::query()->withoutGlobalScopes()->count(), 'a request to report erases nothing');
        event(new ShopperDataRequested($this->shop->id, 'redact', 'a@b.test', '7001'));
        $this->assertSame(0, ShortlistWishlist::query()->withoutGlobalScopes()->where('customer_ref', 'shopify:7001')->count());
    }
}
