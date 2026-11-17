<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asked for products like another ("מה עוד דומה?", "מה הכי קרוב ל'עקידה'?"), the product named or alone on the screen is
 * what the others are measured by, never one of them (Sella, 17.11: "עקידה" itself was "the closest", twice).
 */
final class SimilarAnchorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_product_others_are_asked_to_be_like_is_never_one_of_them(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['4008' => 'עקידה', '624' => 'מי שיש לו למה', '747' => 'חברות ברזל'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => 'https://store.test/'.$id, 'price' => 88, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$id, 'payload' => []]);
            }
        });
        $sent = fn (string $question, array $shown): array => array_values(array_map(fn (array $p): string => (string) $p['external_id'],
            app(AnswerSiteQuestion::class)->snapshot($shop->id, $question, $shown)['productsByRef']));

        // the suggestions on the screen are those asked about, the book they are measured by is not sent
        $closest = $sent('מה הכי קרוב ל"עקידה"?', ['624', '747']);
        $this->assertNotContains('4008', $closest);
        $this->assertContains('624', $closest);
        // alone on the screen, it is the one the others are like: never itself
        $this->assertNotContains('4008', $sent('מה עוד דומה?', ['4008']));
        $this->assertNotContains('4008', $sent('something similar?', ['4008']));
        // a question about it is still about it
        $this->assertContains('4008', $sent('על מה הספר?', ['4008']));
    }
}
