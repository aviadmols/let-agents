<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A search that finds nothing as typed is loosened a word at a time (SearchCatalog::loosened()), never by the word that
 * says what the product is. Gueta, 10.11: "מכונת פוליש" left without "פוליש" showed every pressure washer, while the
 * store's own type filter says "פוליש" is a kind of product, as "מכונת שטיפה" is.
 */
final class LooseKindTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['content_locale' => 'he']);
        Features::override('search.semantic', false, $this->shop->id);
        Features::override('search.pictures', false, $this->shop->id);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            $vocabulary = EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'tools', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'tools', 'name' => ['he' => 'כלי עבודה', 'en' => 'Tools'], 'root_category_external_id' => '*',
                    'product_types' => [
                        ['key' => 'pressure_washer', 'label' => ['he' => 'מכונת שטיפה', 'en' => 'Pressure washer']],
                        ['key' => 'polisher', 'label' => ['he' => 'פוליש', 'en' => 'Polisher']],
                        ['key' => 'drill', 'label' => ['he' => 'מקדחה', 'en' => 'Drill']],
                        ['key' => 'disc', 'label' => ['he' => 'דיסק', 'en' => 'Disc']],
                    ],
                    'attributes' => []]]);
            $products = [
                ['1', 'מכונת שטיפה Lavor 160 בר', 'pressure_washer', []],
                ['2', 'מכונת שטיפה Krauss 215 בר', 'pressure_washer', []],
                ['3', 'מקדחה רוטטת 850W', 'drill', []],
                ['4', 'מקדחה נטענת Makita', 'drill', []],
                // what the store tags it says the words, its title not: found as typed, then left out as no machine
                ['5', 'דיסק ליטוש 16 אינץ', 'disc', ['למכונת פוליש']],
            ];
            foreach ($products as [$id, $title, $type, $tags]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => 500, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => $tags]]);
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => FactKind::Type, 'key' => 'type',
                    'value_text' => $type, 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "f{$id}"]);
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_a_kind_of_product_is_never_the_word_a_search_is_loosened_by(): void
    {
        $polisher = $this->search('מכונת פוליש');

        $this->assertNotContains('1', array_column($polisher['groups']['product'], 'external_id'), 'never the pressure washers');
        $this->assertNotContains('2', array_column($polisher['groups']['product'], 'external_id'));
        $this->assertArrayNotHasKey('loosened', $polisher, 'not loosened to "מכונת"');
    }

    /** @return array<string, mixed> */
    private function search(string $query): array
    {
        return app(SearchCatalog::class)->handle($this->shop->id, $query, count: false);
    }
}
