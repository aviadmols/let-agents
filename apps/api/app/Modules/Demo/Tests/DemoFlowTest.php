<?php

namespace App\Modules\Demo\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Demo\Actions\SweepDemos;
use App\Modules\Demo\Jobs\PrepareDemoJob;
use App\Modules\Demo\Mail\DemoMail;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\PublicStoreFeed;
use App\Modules\Tenancy\Contracts\CreatesShops;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A demo, as whoever asks for it goes through it: the address and the email, the code, a demo store of its own
 * (status demo, so nothing real or scheduled is touched), held to the demo's limits, read from what the store
 * publishes, shown on our page with the floating search, and deleted with everything when it ends.
 */
final class DemoFlowTest extends TestCase
{
    use RefreshDatabase;

    private ?string $code = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        Http::fake(['https://store.test/' => Http::response('<html lang="he"><title>חנות בדיקה</title></html>')]);
    }

    private function start(array $input = []): TestResponse
    {
        return $this->post('/demo', $input + ['url' => 'https://www.store.test/shop', 'email' => 'Owner@Store.test', 'kind' => 'store', 'matters' => 'look', 'consent' => '1']);
    }

    public function test_the_address_and_the_email_send_a_code_and_nothing_is_built_before_it(): void
    {
        $this->get('/demo')->assertOk()->assertSee('data-demo-start', false)->assertSee('ראו את Lets על החנות שלכם');

        $this->start()->assertRedirect();

        $site = DemoSite::query()->sole();
        $this->assertSame(['store.test', 'owner@store.test', DemoSite::PENDING, ['kind' => 'store', 'matters' => 'look']], [$site->host, $site->email, $site->status, $site->choices]);
        Mail::assertSent(DemoMail::class, fn (DemoMail $m): bool => $m->kind === 'code' && $m->hasTo('owner@store.test') && preg_match('/^\d{6}$/', $m->data['code'] ?? '') === 1);
        $this->assertSame(0, Shop::query()->count(), 'no store until the email is proven');
        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-demo-verify', false);
    }

    public function test_a_store_already_on_lets_or_no_address_is_no_demo(): void
    {
        Shop::factory()->create(['domain' => 'store.test']);

        $this->start()->assertSessionHas('demo_error', 'already');
        $this->start(['url' => 'not an address'])->assertSessionHas('demo_error', 'bad_url');
        $this->assertSame(0, DemoSite::query()->count());
    }

    public function test_the_code_builds_a_demo_store_of_its_own_held_to_the_demos_limits(): void
    {
        $this->start();
        $site = DemoSite::query()->sole();
        $code = Mail::sent(DemoMail::class)->first()->data['code'];

        $this->post('/demo/'.$site->token.'/verify', ['code' => '000000'])->assertSessionHas('demo_result', 'wrong');
        $this->post('/demo/'.$site->token.'/verify', ['code' => $code])->assertRedirect('/demo/'.$site->token);

        $site->refresh();
        $shop = Shop::query()->sole();
        $this->assertSame([DemoSite::SCANNING, ShopStatus::Demo, 'store.test'], [$site->status, $shop->status, $shop->domain]);
        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $shop->id)->sole());
        $this->assertSame('demo', $connection->platform);
        $this->assertContains(parse_url((string) config('app.url'), PHP_URL_HOST), $connection->site_info['hosts'], 'the drawer answers on our demo page');
        $this->assertNotNull(StoreConnection::forSite((string) $connection->site_key), 'a demo store has a storefront');
        $this->assertEquals([300, (float) Settings::get('demo.budget_usd'), true, false, 'picture'],
            [Settings::get('catalog.max_products', $shop->id), Settings::get('ai.shop_monthly_cap_usd', $shop->id), Settings::get('ai.shop_cap_stops_shoppers', $shop->id), Settings::get('search.show_cart', $shop->id), Settings::get('search.similar_by', $shop->id)]);
        $this->assertFalse(Features::enabled('enrichment.nightly', $shop->id), 'nothing a night would do');
        $this->assertTrue(Features::enabled('search.photos', $shop->id), 'looks matter: photo search');
        Queue::assertPushed(PrepareDemoJob::class);
        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-demo-scan', false);
    }

    public function test_the_ready_demo_shows_the_floating_search_and_ends_with_its_store_deleted(): void
    {
        $this->start();
        $site = DemoSite::query()->sole();
        $this->post('/demo/'.$site->token.'/verify', ['code' => Mail::sent(DemoMail::class)->first()->data['code']]);
        $site->refresh()->forceFill(['status' => DemoSite::READY, 'expires_at' => now()->addDays(7), 'products' => 12])->save();

        $this->get('/demo/'.$site->token)->assertOk()->assertSee("launcher: 'float'", false)->assertSee('data-demo-bar', false);

        $this->travel(8)->days();
        app(SweepDemos::class)->handle();

        $this->assertSame(DemoSite::EXPIRED, $site->fresh()->status);
        $this->assertSame(0, Shop::query()->count(), 'the demo store and all it holds are gone');
        $this->get('/demo/'.$site->token)->assertOk()->assertSee('data-demo-ended', false);
    }

    public function test_a_store_that_connects_for_real_takes_its_domain_from_its_demo(): void
    {
        $demo = app(CreatesShops::class)->handle(['name' => 'demo', 'domain' => 'store.test', 'platform' => 'woocommerce', 'status' => 'demo']);

        $real = app(CreatesShops::class)->handle(['name' => 'Store', 'domain' => 'https://www.store.test', 'platform' => 'shopify']);

        $this->assertNull(Shop::query()->find($demo->id), 'the demo went');
        $this->assertSame('store.test', $real->domain);
    }

    public function test_a_shopify_stores_public_feed_reads_as_any_feed(): void
    {
        Http::fake(['https://store.test/products.json*' => Http::sequence()
            ->push(['products' => [[
                'id' => 7, 'title' => 'טבעת זהב', 'handle' => 'ring', 'body_html' => '<p>טבעת <b>זהב</b> 14K</p>', 'vendor' => 'Rocks', 'product_type' => 'טבעות', 'tags' => ['זהב'],
                'variants' => [['id' => 70, 'title' => '52', 'option1' => '52', 'price' => '890.00', 'compare_at_price' => '990.00', 'available' => true], ['id' => 71, 'title' => '54', 'option1' => '54', 'price' => '890.00', 'available' => false]],
                'options' => [['name' => 'מידה', 'values' => ['52', '54']]], 'images' => [['src' => 'https://cdn.test/ring.jpg']],
            ]]])
            ->push(['products' => []])]);
        $connection = (new StoreConnection)->forceFill(['site_url' => 'https://store.test', 'site_info' => ['demo' => ['platform' => 'shopify', 'currency' => 'ILS']]]);

        $record = iterator_to_array(app(PublicStoreFeed::class)->products($connection), false)[0][0];

        $this->assertSame(['7', 'טבעת זהב', 'https://store.test/products/ring', 'variable', 'טבעת זהב 14K'], [$record['external_id'], $record['title'], $record['url'], $record['type'], $record['description']]);
        $this->assertSame(['890', '990', true, true], [$record['price']['price'], $record['price']['regular_price'], $record['price']['on_sale'], $record['stock']['in_stock']]);
        $this->assertSame([['name' => 'מידה', 'value' => '52']], $record['variations'][0]['attributes']);
    }
}
