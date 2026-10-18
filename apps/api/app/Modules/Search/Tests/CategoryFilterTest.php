<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Sources\ProductDocuments;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the scan learned reaches the search: the store's categories are a filter the shopper picks; what
 * a product is and is for joins its words (found by "גיזום" though its name says "גוזם"); and its meaning
 * vectors are made from its own text, what its picture shows and its approved facts.
 */
final class CategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var array<string, CatalogProduct> */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['content_locale' => 'he']);

        $this->inShop(function (): void {
            $garden = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'g', 'name' => 'גינון', 'path' => ['גינון'], 'depth' => 0, 'hash' => 'g', 'product_count' => 2]);
            $screws = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 's', 'name' => 'ברגים', 'path' => ['ברגים'], 'depth' => 0, 'hash' => 's', 'product_count' => 2]);
            $lonely = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'l', 'name' => 'שונות', 'path' => ['שונות'], 'depth' => 0, 'hash' => 'l', 'product_count' => 1]);

            foreach ([['1', 'גוזם גדר חי חשמלי 620W', [$garden]], ['2', 'מזמרה לגיזום', [$garden, $lonely]], ['3', 'בורג עץ 4X40', [$screws]], ['4', 'בורג גבס שחור', [$screws]]] as [$id, $title, $categories]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title, 'url' => "https://www.store.test/p/$id", 'price' => '20', 'in_stock' => true, 'purchasable' => true, 'hash' => "h$id", 'payload' => ['description' => 'כלי גינון עוצמתי המחובר לחשמל ביתי.']]);
                $product->categories()->attach(array_map(fn (CatalogCategory $c) => $c->id, $categories));
                $this->products[$id] = $product;
            }

            foreach ([[FactKind::Use, 'use', 'גיזום שיחים וגדרות חיות'], [FactKind::Type, 'type', 'גוזם'], [FactKind::Highlight, 'highlight', 'הספק של 620W לחיתוך ענפים בעובי בינוני']] as [$kind, $key, $value]) {
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $this->products['1']->id, 'kind' => $kind, 'key' => $key, 'value_text' => $value, 'quote' => $value,
                    'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 'h'.$key, 'model' => 'code']);
            }
            // A rejected fact says nothing.
            EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $this->products['1']->id, 'kind' => FactKind::Use, 'key' => 'use', 'value_text' => 'חיתוך מתכת', 'quote' => 'x',
                'origin' => FactOrigin::Code, 'status' => FactStatus::Rejected, 'input_hash' => 'hr', 'model' => 'code']);
            RetrievalImage::query()->create(['shop_id' => $this->shop->id, 'product_id' => $this->products['1']->id, 'external_id' => '1', 'title' => 'גוזם', 'image_url' => 'https://www.store.test/1.jpg', 'url_hash' => 'a', 'caption' => 'גוזם גדר חי חשמלי כתום ושחור עם להב ארוך', 'captioned_at' => now()]);
        });
    }

    public function test_the_store_categories_are_a_filter_and_what_a_product_is_for_joins_its_words(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $data = json_decode((string) $this->inShop(fn () => SearchIndex::query()->sole()->items), true);

        $at = array_search('category', array_column($data['facets'], 'k'), true);
        $this->assertNotFalse($at, 'the categories are a filter');
        $facet = $data['facets'][$at];
        $this->assertSame('קטגוריה', $facet['l']);
        $this->assertEqualsCanonicalizing(['גינון', 'ברגים'], array_column($facet['v'], 'l'), 'a category with one product is not worth a filter');

        $garden = $at * 1000 + array_search('גינון', array_column($facet['v'], 'l'), true);
        $items = collect($data['items'])->keyBy('id');
        $this->assertContains($garden, $items['p:1']['f']);
        $this->assertContains($garden, $items['p:2']['f']);
        $this->assertNotContains($garden, $items['p:3']['f']);

        $this->assertStringContainsString('גיזום שיחים וגדרות חיות', $items['p:1']['kw'], 'what it is for is found');
        $this->assertStringNotContainsString('חיתוך מתכת', $items['p:1']['kw'], 'a rejected fact is not');
    }

    public function test_the_meaning_vectors_say_what_the_picture_shows_and_what_was_approved(): void
    {
        $text = $this->inShop(fn () => collect(iterator_to_array((new ProductDocuments)->documents($this->shop->id), false))->firstWhere('title', 'גוזם גדר חי חשמלי 620W')->text);

        $this->assertStringContainsString('כלי גינון עוצמתי המחובר לחשמל ביתי.', $text, 'the store\'s own description');
        $this->assertStringContainsString('Picture: גוזם גדר חי חשמלי כתום ושחור עם להב ארוך', $text);
        $this->assertStringContainsString('Use: גיזום שיחים וגדרות חיות', $text);
        $this->assertStringContainsString('Highlight: הספק של 620W לחיתוך ענפים בעובי בינוני', $text);
        $this->assertStringNotContainsString('חיתוך מתכת', $text);
        $this->assertSame($text, $this->inShop(fn () => collect(iterator_to_array((new ProductDocuments)->documents($this->shop->id), false))->firstWhere('title', 'גוזם גדר חי חשמלי 620W')->text), 'the same facts give the same text');
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
