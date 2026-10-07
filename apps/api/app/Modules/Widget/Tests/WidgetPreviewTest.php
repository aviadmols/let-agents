<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The display settings screen shows the module as the shop's shoppers would, from the saved
 * settings, on a sample page of the shop; nothing in the preview is counted or sent to a model,
 * and a shop manager can preview only their own shop.
 */
final class WidgetPreviewTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_shop_manager_sees_the_module_on_a_sample_page_of_their_shop(): void
    {
        Settings::set('widget.layout', 'tags', $this->shop->id);
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);

        $page = $this->actingAs($merchant)->get("/widget-preview/{$this->shop->slug}?id=202")->assertOk();

        $page->assertSee('בורג נירוסטה לדק 5X50');
        $page->assertSee('"layout":"tags"', false);
        $page->assertSee('let-agents.js', false);
        $page->assertSee('navigator.sendBeacon = function () { return true; }', false);
        $this->assertSame('SAMEORIGIN', $page->headers->get('X-Frame-Options'));

        $this->actingAs($merchant)->followingRedirects()->get("/merchant/{$this->shop->slug}/display")
            ->assertOk()
            ->assertSee('data-widget-preview', false)
            ->assertSee(url("widget-preview/{$this->shop->slug}"), false);
    }

    public function test_nobody_previews_a_shop_that_is_not_theirs(): void
    {
        $other = Shop::factory()->create();
        $merchant = User::factory()->create();
        $merchant->attachShop($other);

        $this->get("/widget-preview/{$this->shop->slug}")->assertNotFound();
        $this->actingAs($merchant)->get("/widget-preview/{$this->shop->slug}")->assertNotFound();
        $this->actingAs(User::factory()->operator()->create())->get("/widget-preview/{$this->shop->slug}")->assertOk();
    }
}
