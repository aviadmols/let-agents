<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\DescribedProducts;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sela: "7 באוקטובר" found the two books with the date in their title, while some 35 others are about
 * it only in their description. Those come after the title matches, when the words found few products,
 * and only where the description says the phrase: the words next to each other, in order.
 */
final class DescribedProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_whose_description_says_the_phrase_come_after_those_whose_title_does(): void
    {
        $shop = $this->books([
            '1' => ['להבין את ה-7 באוקטובר', 'ספר על המלחמה.'],
            '2' => ['חטוף', '<p>סיפורו של אלי שרעבי, שנחטף מביתו בבארי ב־7 באוקטובר 2023.</p>'],
            '3' => ['אבא בא', 'מאז השבעה באוקטובר הוא לא חזר הביתה.'],
            '4' => ['ספר בישול', 'מתכונים לחגים. הספר יצא ב-7 בנובמבר, ובאוקטובר יצא החלק השני.'],
            '5' => ['הפסיכולוגיה של הכסף', 'עצות על כסף.'],
        ]);

        foreach (['7 באוקטובר', 'שבע באוקטובר', 'השבעה באוקטובר'] as $query) {
            $ids = array_column(app(SearchCatalog::class)->handle($shop->id, $query, ['product'], false, null, false)['groups']['product'], 'external_id');

            $this->assertSame('1', $ids[0] ?? null, $query.': the title match first');
            $this->assertContains('2', $ids, $query.': its description says it ("ב־7 באוקטובר")');
            $this->assertContains('3', $ids, $query.': "השבעה" is 7');
            $this->assertNotContains('4', $ids, $query.': "7" and "באוקטובר" apart are not the date');
            $this->assertNotContains('5', $ids, $query);
        }
    }

    public function test_the_phrase_pattern(): void
    {
        $this->assertMatchesRegularExpression((string) DescribedProducts::pattern('7 באוקטובר'), ' נחטף ב 7 באוקטובר 2023 ');
        $this->assertMatchesRegularExpression((string) DescribedProducts::pattern('שבע באוקטובר'), ' את ה 7 באוקטובר ');
        $this->assertDoesNotMatchRegularExpression((string) DescribedProducts::pattern('7 באוקטובר'), ' 17 באוקטובר ');
        $this->assertNull(DescribedProducts::pattern('א'), 'too short to mean anything');
    }

    /** @param array<string, array{0: string, 1: string}> $books */
    private function books(array $books): Shop
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop, $books): void {
            foreach ($books as $id => [$title, $description]) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://b.test/p/$id", 'price' => 80, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => ['description' => $description]]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);

        return $shop;
    }
}
