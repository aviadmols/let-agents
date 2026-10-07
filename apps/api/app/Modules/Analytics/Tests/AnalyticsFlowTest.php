<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Analytics\Models\AnalyticsOrderImport;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AnalyticsFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const VID = 'anon-visitor1234567890abcd';

    private Shop $shop;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
    }

    public function test_the_site_key_is_derived_from_the_token_the_way_the_plugin_derives_it(): void
    {
        $hash = hash('sha256', self::TOKEN);

        $this->assertSame(substr(hash('sha256', 'let-agents-site|'.$hash), 0, 24), $this->site);
        $this->assertSame($this->site, StoreConnection::forSite($this->site)?->site_key);
        $this->assertNull(StoreConnection::forSite('not-a-key'));
        $this->assertTrue(StoreConnection::forSite($this->site)->allowsOrigin('https://store.test'));
        $this->assertFalse(StoreConnection::forSite($this->site)->allowsOrigin('https://evil.test'));
    }

    public function test_a_beacon_is_checked_against_the_event_spec_and_stored_once(): void
    {
        $beacon = $this->beacon([
            $this->event('page_view'),
            $this->event('exposure', ['candidate' => true, 'data' => ['visible_ms' => 1200, 'ratio' => 1]]),
            $this->event('add_to_cart', ['candidate' => true, 'data' => ['source' => 'widget', 'product_id' => '55', 'quantity' => 1, 'result' => 'added']]),
        ]);

        $this->sendBeacon($beacon)->assertStatus(202);
        $this->sendBeacon($beacon)->assertStatus(202);

        $events = app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsEvent::query()->orderBy('id')->get());
        $this->assertCount(3, $events, 'the same event ids count once');
        $this->assertSame(['page_view', 'exposure', 'add_to_cart'], $events->pluck('type')->all());
        $this->assertSame('10', $events[0]->product_external_id, 'the page the visitor was on');
        $this->assertSame('55', $events[2]->item_external_id, 'the product added to the cart');
        $this->assertSame('complement', $events[2]->model);
        $this->assertNotSame(self::VID, $events[0]->visitor_hash, 'visitors are stored only as a hash');
    }

    public function test_a_beacon_the_spec_does_not_allow_is_refused_whole(): void
    {
        $withCandidate = $this->event('page_view');
        $withCandidate['candidate'] = ['id' => 'specs', 'version' => 1, 'model' => 'specs', 'slot' => 'panel'];

        $this->sendBeacon($this->beacon([$this->event('page_view'), $withCandidate]))->assertStatus(422);

        $withQuery = $this->event('page_view');
        $withQuery['page']['path'] = '/product/x?email=a@b.test';
        $this->sendBeacon($this->beacon([$withQuery]))->assertStatus(422);

        $otherShop = $this->beacon([$this->event('page_view')]);
        $otherShop['shop'] = Shop::factory()->create()->id;
        $this->sendBeacon($otherShop)->assertStatus(422)->assertJsonPath('problems.0', 'other_shop');

        $this->sendBeacon($this->beacon([$this->event('page_view')]), origin: 'https://evil.test')->assertStatus(403);
        $this->call('POST', '/api/v1/widget/aaaaaaaaaaaaaaaaaaaaaaaa/events', content: '{}')->assertStatus(404);

        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsEvent::query()->count()));

        Features::override('analytics.collect', false, $this->shop->id);
        $this->sendBeacon($this->beacon([$this->event('page_view')]))->assertStatus(202);
        $this->assertSame(0, app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsEvent::query()->count()), 'collection off stores nothing');
    }

    public function test_an_order_from_the_plugin_is_attributed_to_what_the_visitor_added_from_the_widget(): void
    {
        $this->sendBeacon($this->beacon([
            $this->event('page_view'),
            $this->event('open', ['candidate' => true]),
            $this->event('add_to_cart', ['candidate' => true, 'data' => ['source' => 'widget', 'product_id' => '55', 'quantity' => 1, 'result' => 'added']]),
        ]))->assertStatus(202);

        $order = [
            'order_ref' => hash('sha256', 'order-1001'),
            'total' => 649.9,
            'currency' => 'ils',
            'ordered_at' => time(),
            'vid' => self::VID,
            'items' => [
                ['product_id' => '55', 'quantity' => 1, 'total' => 150],
                ['product_id' => '10', 'quantity' => 1, 'total' => 499.9],
            ],
        ];

        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders", json_encode($order))
            ->assertStatus(202)->assertJson(['stored' => true, 'assisted' => true]);
        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders", json_encode($order))
            ->assertStatus(202)->assertJson(['stored' => false]);

        $stored = app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsOrder::query()->sole());
        $this->assertSame('150.00', $stored->attributed_total);
        $this->assertSame(['55'], $stored->attributed_items);
        $this->assertSame('ILS', $stored->currency);

        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders", json_encode($order), signature: str_repeat('0', 64))->assertStatus(401);
        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders", json_encode($order), timestamp: (string) (time() - 3600))->assertStatus(401);
        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders", json_encode(['order_ref' => 'Robert Smith'] + $order))->assertStatus(422);
    }

    public function test_past_orders_arrive_in_pages_without_a_visitor_and_stay_out_of_the_report(): void
    {
        $order = fn (int $n, int $at, array $extra = []): array => $extra + [
            'order_ref' => hash('sha256', "order-{$n}"),
            'total' => 100,
            'currency' => 'ILS',
            'ordered_at' => $at,
            'vid' => self::VID,
            'items' => [['product_id' => '10', 'quantity' => 1, 'total' => 60], ['product_id' => '55', 'quantity' => 2, 'total' => 40]],
        ];
        $old = now()->subMonths(14)->getTimestamp();
        $recent = now()->subDays(2)->getTimestamp();

        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders/history", json_encode([
            'first' => true, 'expected' => 3, 'orders' => [$order(1, $old), $order(2, $recent)],
        ]))->assertStatus(202)->assertJson(['received' => 2, 'stored' => 2]);

        // The last page, with one order sent again and one the validation refuses.
        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders/history", json_encode([
            'last' => true, 'orders' => [$order(2, $recent), $order(3, $recent, ['currency' => 'shekels'])],
        ]))->assertStatus(202)->assertJson(['received' => 2, 'stored' => 0]);

        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders/history", json_encode(['orders' => [$order(4, $recent)]]), signature: str_repeat('0', 64))->assertStatus(401);
        $this->signed('POST', "/api/v1/plugin/{$this->site}/orders/history", json_encode(['orders' => array_fill(0, 101, $order(5, $recent))]))->assertStatus(422);

        [$orders, $import] = app(TenantContext::class)->run($this->shop->id, fn () => [AnalyticsOrder::query()->get(), AnalyticsOrderImport::query()->sole()]);

        $this->assertCount(2, $orders);
        $this->assertSame([AnalyticsOrder::SOURCE_HISTORY], $orders->pluck('source')->unique()->values()->all());
        $this->assertSame([null], $orders->pluck('visitor_hash')->unique()->values()->all());
        $this->assertFalse($orders->contains('assisted', true));
        $this->assertSame([3, 4, 2, 1], [$import->expected, $import->received, $import->stored, $import->refused]);
        $this->assertNotNull($import->finished_at);
        $this->assertSame(date('Y-m', $old), $import->oldest_ordered_at->format('Y-m'));

        $report = $this->signed('GET', "/api/v1/plugin/{$this->site}/reports?days=7", '')->assertOk()->json('data');
        $this->assertSame(0, $report['totals']['orders']);
    }

    public function test_the_plugin_and_the_operator_see_the_same_report(): void
    {
        app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->create([
            'shop_id' => $this->shop->id, 'external_id' => '10', 'type' => 'simple', 'status' => 'publish',
            'title' => 'מסור אנכי', 'hash' => 'x', 'payload' => [],
        ]));

        $this->sendBeacon($this->beacon([
            $this->event('page_view'),
            $this->event('exposure', ['candidate' => true, 'data' => ['visible_ms' => 1000, 'ratio' => 0.8]]),
            $this->event('open', ['candidate' => true]),
            $this->event('add_to_cart', ['candidate' => true, 'data' => ['source' => 'widget', 'product_id' => '55', 'quantity' => 1, 'result' => 'added']]),
        ]))->assertStatus(202);

        $report = $this->signed('GET', "/api/v1/plugin/{$this->site}/reports?days=7", '')->assertOk()->json('data');

        $this->assertSame(1, $report['totals']['page_views']);
        $this->assertSame(1, $report['totals']['visitors']);
        $this->assertSame(1, $report['totals']['impressions']);
        $this->assertSame(1, $report['totals']['widget_add_to_cart']);
        $this->assertEquals(1, $report['totals']['open_rate']);
        $this->assertSame('מסור אנכי', $report['hot_pages'][0]['title']);
        $this->assertSame(1, $report['hot_pages'][0]['add_to_cart']);
        $this->assertSame('complement', $report['hot_models'][0]['model']);
        $this->assertSame('55', $report['top_products'][0]['id']);
        $this->assertCount(8, $report['daily']);

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)->get('/operator/analytics?shop='.$this->shop->id)
                ->assertOk()
                ->assertSee('מסור אנכי');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function beacon(array $events): array
    {
        return [
            'v' => 1, 'shop' => $this->shop->id, 'vid' => self::VID, 'session' => 'session12345',
            'sent_at' => (int) (microtime(true) * 1000), 'holdout' => false, 'events' => $events,
        ];
    }

    /**
     * @param  array{candidate?: bool, data?: array<string, mixed>}  $extra
     * @return array<string, mixed>
     */
    private function event(string $type, array $extra = []): array
    {
        $event = [
            'id' => 'evt'.bin2hex(random_bytes(8)),
            'type' => $type,
            'ts' => (int) (microtime(true) * 1000),
            'page' => ['type' => 'product', 'path' => '/product/jigsaw/', 'product_id' => '10'],
        ];

        if ($extra['candidate'] ?? false) {
            $event += [
                'candidate' => ['id' => 'complement', 'version' => 1, 'model' => 'complement', 'slot' => 'panel'],
                'bank_version' => 1,
                'eligible' => ['position', 'complement'],
                'bucket' => 'none',
            ];
        }

        if (isset($extra['data'])) {
            $event['data'] = $extra['data'];
        }

        return $event;
    }

    /** @param array<string, mixed> $beacon */
    private function sendBeacon(array $beacon, string $origin = 'https://store.test'): TestResponse
    {
        return $this->call('POST', "/api/v1/widget/{$this->site}/events", server: ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'text/plain'], content: json_encode($beacon));
    }

    private function signed(string $method, string $path, string $body, ?string $signature = null, ?string $timestamp = null): TestResponse
    {
        $timestamp ??= (string) time();
        $signature ??= SiteKeys::signature(self::TOKEN, $timestamp, $method, $path, $body);

        return $this->call($method, $path, server: [
            'HTTP_X_LETAGENTS_TIMESTAMP' => $timestamp,
            'HTTP_X_LETAGENTS_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }
}
