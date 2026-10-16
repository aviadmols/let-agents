<?php

namespace App\Modules\Widget\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The display settings screen shows the Lets Search drawer as the shop's shoppers would, on a
 * sample product page of the shop, from its real information; nothing in the preview is counted
 * or sent to a model, and a shop manager can preview only their own shop.
 */
final class WidgetPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_pppppppppppppppppppppppppppppppppppppppppppppppp';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach (['201' => 'עץ איפאה לדק 21X145', '202' => 'בורג נירוסטה לדק 5X50'] as $id => $title) {
                CatalogProduct::query()->create([
                    'shop_id' => $this->shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish',
                    'title' => $title, 'url' => "https://www.store.test/p/{$id}", 'image_url' => "https://www.store.test/i/{$id}.jpg",
                    'price' => '89.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => [],
                ]);
            }
        });
    }

    public function test_the_shop_manager_sees_the_drawer_on_a_sample_product_of_their_shop(): void
    {
        $connection = $this->connect();
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);

        $page = $this->actingAs($merchant)->get("/widget-preview/{$this->shop->slug}?id=202&theme=store")->assertOk();

        $page->assertSee('בורג נירוסטה לדק 5X50');
        // The drawer the stores load, set up for this shop and this product, as the team's preview.
        $page->assertSee(route('api.search.lets-search'), false);
        $page->assertDontSee('let-agents.js', false);
        $page->assertSee('window.LetsSearchConfig', false);
        $page->assertSee('site: '.json_encode($connection->site_key), false);
        $page->assertSee('preview: true, productId: "202"', false);
        $page->assertSee('theme: "store"', false);
        $page->assertSee('<form class="cart"', false);
        // Nothing a shopper would send leaves the page.
        $page->assertSee('navigator.sendBeacon = function () { return true; }', false);
        $page->assertSee('&typing=1', false);
        $this->assertSame("frame-ancestors 'self' https://admin.shopify.com https://*.myshopify.com;", $page->headers->get('Content-Security-Policy'), 'our own screen, also inside the Shopify admin');
        $this->assertNull($page->headers->get('X-Frame-Options'));

        $this->actingAs($merchant)->followingRedirects()->get("/merchant/{$this->shop->slug}/display")
            ->assertOk()
            ->assertSee('data-widget-preview', false)
            ->assertSee(url("widget-preview/{$this->shop->slug}"), false);
    }

    public function test_a_shop_without_a_connection_says_so_instead_of_a_drawer(): void
    {
        $page = $this->actingAs(User::factory()->operator()->create())->get("/widget-preview/{$this->shop->slug}")->assertOk();

        $page->assertSee(__('widget::preview.no_connection', [], 'he'));
        $page->assertDontSee('window.LetsSearchConfig', false);
    }

    public function test_nobody_previews_a_shop_that_is_not_theirs(): void
    {
        $this->connect();
        $other = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($other);

        $this->get("/widget-preview/{$this->shop->slug}")->assertNotFound();
        $this->actingAs($merchant)->get("/widget-preview/{$this->shop->slug}")->assertNotFound();
        $this->actingAs(User::factory()->operator()->create())->get("/widget-preview/{$this->shop->slug}")->assertOk();
    }

    private function connect(): StoreConnection
    {
        return app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN,
        ]));
    }
}
