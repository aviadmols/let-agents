<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "שבע באוקטובר" finds the October 7th books however their titles write the number, and one title
 * holding both words does not hide the others that hold most of them.
 */
final class NumberWordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_number_as_a_word_finds_it_as_digits_and_one_whole_match_hides_nothing(): void
    {
        LoadedIndex::forget();
        $shop = Shop::factory()->create(['content_locale' => 'he']);
        app(TenantContext::class)->run($shop->id, function () use ($shop): void {
            foreach (['1' => 'ספרי השבעה באוקטובר', '2' => 'להבין את ה-7 באוקטובר', '3' => 'תחנות 7, פקדה אותנו השעה', '4' => 'ספר בישול'] as $id => $title) {
                CatalogProduct::query()->create(['shop_id' => $shop->id, 'external_id' => (string) $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://b.test/p/$id", 'price' => 80, 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => []]);
            }
        });
        app(BuildSearchIndex::class)->handle($shop->id);

        $ids = array_column(app(SearchCatalog::class)->handle($shop->id, 'שבע באוקטובר', ['product'], false, null, false)['groups']['product'], 'external_id');

        $this->assertContains('1', $ids);
        $this->assertContains('2', $ids, '"שבע" is "7", and one whole match hides nothing');
        $this->assertNotContains('4', $ids);
    }
}
