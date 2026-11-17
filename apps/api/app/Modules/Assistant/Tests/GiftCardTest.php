<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Asked for a gift ("מתנה לאמא"), the writer is given the store's gift card too, last, to offer for one not sure what to
 * choose (as Aviad asked, 17.11; answer_site v31). Never for a question that is no gift.
 */
final class GiftCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gift_brings_the_stores_gift_card_last(): void
    {
        $shop = Shop::factory()->create();
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'טבעת וינטג׳ זהב', '2' => 'שרשרת זהב עדינה', '9' => 'כרטיס מתנה דיגיטלי'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => 'https://store.test/'.$id, 'price' => 300, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$id, 'payload' => []]);
            }
        });
        $sent = function (string $question, int $version) use ($shop): array {
            $answers = app()->make(AnswerSiteQuestion::class);
            (new ReflectionProperty(AnswerSiteQuestion::class, 'promptVersions'))->setValue($answers, ['answer_site' => $version]);

            return array_values(array_map(fn (array $p): string => (string) $p['external_id'], $answers->snapshot($shop->id, $question, ['1', '2'])['productsByRef']));
        };

        $this->assertSame('9', array_slice($sent('מחפשת מתנה לאמא', 31), -1)[0], 'last, after what can be given itself');
        $this->assertNotContains('9', $sent('טבעת זהב לעצמי', 31), 'no gift asked');
    }
}
