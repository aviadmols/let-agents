<?php

namespace App\Modules\Shortlist\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shortlist\Models\ShortlistWishlist;
use App\Modules\Shortlist\Support\SavedForLater;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "What they save for later" on the Shoppers screen (the redesign, 11.2026): the lists changed in the period, the
 * products in them, the products in the most lists (a product twice in one list counts once), and the spreadsheet.
 */
final class SavedForLaterTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_lists_of_the_period_and_the_products_saved_most(): void
    {
        $shop = Shop::factory()->create();

        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'תיק קלוע', '2' => 'בושם ענבר', '3' => 'שמלת פשתן'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'hash' => 'h'.$id, 'payload' => [], 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'image_url' => "https://shop.test/{$id}.jpg", 'in_stock' => true, 'purchasable' => true]);
            }
            foreach ([['1', '2'], ['1', '1'], ['3', '1'], []] as $i => $items) {
                ShortlistWishlist::query()->create(['shop_id' => $shop->id, 'token_hash' => 't'.$i, 'share_code' => 'code0000000'.$i, 'items' => $items]);
            }
            $old = ShortlistWishlist::query()->create(['shop_id' => $shop->id, 'token_hash' => 'old', 'share_code' => 'code00000old', 'items' => ['2', '2', '3']]);
            $old->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();

            $saved = app(SavedForLater::class);
            $this->assertSame(['lists' => 3, 'products' => 6], $saved->numbers($shop, now()->subDays(7), now()->addMinute()));
            $this->assertSame([
                ['id' => '1', 'title' => 'תיק קלוע', 'picture' => 'https://shop.test/1.jpg', 'saved' => 3],
                ['id' => '2', 'title' => 'בושם ענבר', 'picture' => 'https://shop.test/2.jpg', 'saved' => 1],
            ], $saved->top($shop, now()->subDays(7), now()->addMinute(), 2));

            $csv = $saved->export($shop);
            ob_start();
            $csv->sendContent();
            $this->assertStringContainsString('תיק קלוע', (string) ob_get_clean());
        });
    }
}
