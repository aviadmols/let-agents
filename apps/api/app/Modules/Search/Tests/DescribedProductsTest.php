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

    public function test_the_store_subject_is_no_kind_and_what_a_description_says_tells_products_apart(): void
    {
        // Mycolivia: "פטריה" opens a few titles ("פטריית ריישי") and every formula is made of mushrooms ("מיקו דרים"):
        // "פטריה לשינה" locked to the titles opening with it, and showed none. "קשב וריכוז" is in one description.
        $shop = $this->books([
            '1' => ['פטריית ריישי', 'פטריה לרוגע.'],
            '2' => ['פטריית קורדיספס', 'פטריות לאנרגיה וריכוז.'],
            '3' => ['מיקו דרים', 'פורמולת פטריות לשינה טובה.'],
            '4' => ['מיקו באלאנס', 'פטריות לשיפור הקשב והריכוז.'],
            '5' => ['Myco Focus', 'Mushrooms for focusing and attention.'],
            '6' => ['Myco Calm', 'Mushrooms for a calm mind.'],
        ]);
        $hash = 'test';
        $all = ['p:1', 'p:2', 'p:3', 'p:4', 'p:5', 'p:6'];

        app(TenantContext::class)->run($shop->id, function () use ($shop, $hash, $all): void {
            $this->assertTrue(DescribedProducts::subject($shop->id, $hash, 'פטריה', 2), 'opens 2 titles, said by 4 of 6');
            $this->assertFalse(DescribedProducts::subject($shop->id, $hash, 'פטריה', 3), 'said by hardly more than the titles it opens: a kind');
            $this->assertFalse(DescribedProducts::subject($shop->id, $hash, 'שינה', 0), 'said by one');

            $this->assertSame(['p:4'], DescribedProducts::saying($shop->id, $hash, 'פטריה לקשב וריכוז', $all), 'the subject aside, every other word said');
            $this->assertSame(['p:5'], DescribedProducts::saying($shop->id, $hash, 'mushroom for focus', $all), 'in English: "focusing" says "focus"');
            $this->assertSame([], DescribedProducts::saying($shop->id, $hash, 'פטריה', $all), 'nothing tells them apart');
            // Mycolivia: "מה טוב ללישון" kept the one product whose description says "טוב"; a question's own words are no part of it
            $this->assertSame(['p:2', 'p:4'], DescribedProducts::saying($shop->id, $hash, 'מה טוב לריכוז', $all));
        });
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
