<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Search\Models\SearchClick;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the search led to: adds to the cart from its results or the assistant's picks, counted per
 * query and product, and the orders of the same visitor that hold those products, reported by the
 * Shopify pixel or the WooCommerce plugin. Kept apart from what the widget led to.
 */
final class SearchPurchasesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private const VID = 'anon-searchvisitor0123456789';

    private Shop $shop;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create(['currency' => 'ILS']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'platform' => 'shopify', 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);

        $this->inShop(function (): void {
            foreach (['7915917377601' => 'עגילים צמודים כדורי זהב', '52931' => 'טבעת רובי', '61258' => 'שרשרת כסף'] as $id => $title) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://www.store.test/p/{$id}", 'price' => '450.00', 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [],
                ]);
            }
        });
    }

    public function test_an_add_from_the_search_is_counted_and_an_order_of_it_is_the_searchs(): void
    {
        $this->events([
            ['type' => 'click', 'q' => 'עגילים עד 500', 'id' => 'p:7915917377601', 'title' => 'עגילים צמודים כדורי זהב'],
            ['type' => 'add_to_cart', 'q' => 'עגילים עד 500', 'id' => 'p:7915917377601', 'title' => 'עגילים צמודים כדורי זהב', 'path' => '/products/x'],
            ['type' => 'add_to_cart', 'q' => 'עגילים עד 500', 'id' => 'c:9', 'title' => 'not a product'],
        ], self::VID)->assertNoContent();

        $term = $this->inShop(fn () => SearchTerm::query()->sole());
        $this->assertSame([1, 1], [$term->clicks, $term->added]);
        $this->assertSame(1, $this->inShop(fn () => SearchClick::query()->sole())->added);
        $event = $this->inShop(fn () => AnalyticsEvent::query()->sole());
        $this->assertSame(['add_to_cart', 'search', 'added', '7915917377601', 'search'], [$event->type, $event->source, $event->result, $event->item_external_id, $event->page_type]);
        $this->assertSame(AnalyticsEvent::visitorHash($this->shop->id, self::VID), $event->visitor_hash, 'the visitor, only as a hash');

        // The Shopify pixel at checkout: the earrings came from the search, the ring did not.
        $order = ['order_id' => 'gid://shopify/Order/5550001', 'total' => 1100, 'currency' => 'ILS', 'vid' => self::VID, 'items' => [
            ['product_id' => 'gid://shopify/Product/7915917377601', 'quantity' => 1, 'total' => 450],
            ['product_id' => '52931', 'quantity' => 1, 'total' => 650],
        ]];
        $this->pixel($order)->assertStatus(202)->assertJson(['stored' => true]);
        $this->pixel($order)->assertStatus(202)->assertJson(['stored' => false], 'one order, once');

        $stored = $this->inShop(fn () => AnalyticsOrder::query()->sole());
        $this->assertSame(['450.00', ['7915917377601'], 'pixel'], [$stored->search_total, $stored->search_items, $stored->reported_by]);
        $this->assertSame(['0.00', []], [$stored->attributed_total, $stored->attributed_items], 'nothing of it is the widget\'s');
        $this->assertFalse($stored->assisted, 'the widget did not assist it');
        $this->assertStringNotContainsString('5550001', $stored->order_ref, 'the order number only as a hash');

        // What the search led to, on its analytics.
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $page = $this->actingAs($merchant)->get("/merchant/{$this->shop->slug}/analytics/search")->assertOk();
        $page->assertSee('data-total="added"', false)->assertSee('data-total="purchases"', false)->assertSee('₪450.00')
            ->assertSee('data-bought', false)->assertSee('עגילים צמודים כדורי זהב');
    }

    public function test_without_a_visitor_an_add_is_counted_but_nothing_is_attributed(): void
    {
        $this->events([['type' => 'add_to_cart', 'q' => 'טבעת', 'id' => 'p:52931', 'title' => 'טבעת רובי']])->assertNoContent();

        $this->assertSame(1, $this->inShop(fn () => SearchTerm::query()->sole())->added);
        $this->assertSame(0, $this->inShop(fn () => AnalyticsEvent::query()->count()));

        $this->pixel(['order_id' => '77', 'total' => 650, 'currency' => 'ILS', 'items' => [['product_id' => '52931', 'quantity' => 1, 'total' => 650]]])->assertStatus(202);
        $this->assertNull($this->inShop(fn () => AnalyticsOrder::query()->sole())->search_items);
    }

    public function test_an_add_from_the_search_long_before_the_order_is_not_the_searchs(): void
    {
        $this->events([['type' => 'add_to_cart', 'q' => 'טבעת', 'id' => 'p:52931', 'title' => 'טבעת רובי']], self::VID);
        $this->travel(8)->days();

        $this->pixel(['order_id' => '78', 'total' => 650, 'currency' => 'ILS', 'vid' => self::VID, 'items' => [['product_id' => '52931', 'quantity' => 1, 'total' => 650]]])->assertStatus(202);
        $this->assertNull($this->inShop(fn () => AnalyticsOrder::query()->sole())->search_items, 'outside the 7-day window');
    }

    public function test_the_pixel_reports_only_real_orders_of_a_shopify_store(): void
    {
        $this->pixel(['order_id' => '79', 'total' => 10, 'currency' => 'ILS', 'items' => [['product_id' => '123', 'quantity' => 1, 'total' => 10]]])->assertStatus(422);
        $this->pixel(['order_id' => 'DROP TABLE', 'total' => 10, 'currency' => 'ILS', 'items' => []])->assertStatus(422);
        $this->pixel(['order_id' => '80', 'total' => 450, 'currency' => 'ILS', 'vid' => 'not-ours', 'items' => [['product_id' => '52931', 'quantity' => 1, 'total' => 450]]])
            ->assertStatus(202)->assertJson(['stored' => true], 'a visitor id the scripts did not make: kept, unattributed');
        $this->call('POST', '/api/v1/pixel/'.str_repeat('a', 24).'/orders', server: ['CONTENT_TYPE' => 'text/plain'], content: '{}')->assertNotFound();

        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $this->shop->id)->update(['platform' => 'woocommerce']));
        $this->pixel(['order_id' => '81', 'total' => 450, 'currency' => 'ILS', 'items' => [['product_id' => '52931', 'quantity' => 1, 'total' => 450]]])->assertNotFound();
    }

    /** @param list<array<string, mixed>> $events */
    private function events(array $events, ?string $vid = null): TestResponse
    {
        return $this->call('POST', "/api/v1/search/{$this->site}/events", server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://www.store.test'],
            content: (string) json_encode(array_filter(['events' => $events, 'vid' => $vid])));
    }

    /** @param array<string, mixed> $order */
    private function pixel(array $order): TestResponse
    {
        return $this->call('POST', "/api/v1/pixel/{$this->site}/orders", server: ['CONTENT_TYPE' => 'text/plain'], content: (string) json_encode($order));
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
