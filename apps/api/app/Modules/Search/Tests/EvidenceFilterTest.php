<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\Evidence;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A value a check found wrong for a product is not held by its words, and within a filter the products the store itself
 * says the value of come first. Rocks QA, 17.11: "עגילי חישוק זהב" opened with studded earrings whose picture's words
 * said "חישוק", the style itself checked and rejected for them, before the hoops their titles name.
 */
final class EvidenceFilterTest extends TestCase
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
            $vocabulary = EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'jewelry', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'jewelry', 'name' => ['he' => 'תכשיטים', 'en' => 'Jewelry'], 'root_category_external_id' => '*',
                    'product_types' => [['key' => 'earrings', 'label' => ['he' => 'עגילים', 'en' => 'Earrings']]],
                    'attributes' => [['key' => 'earring_style', 'type' => 'enum', 'multiple' => true, 'label' => ['he' => 'סגנון עגיל', 'en' => 'Earring style'],
                        'values' => [['key' => 'hoops', 'label' => ['he' => 'חישוקים', 'en' => 'Hoops']], ['key' => 'studs', 'label' => ['he' => 'צמודים', 'en' => 'Studs']]]]]]]);
            // id, title, tags (its picture's words reach its keywords the same way), the style's fact: [status, how it was read]
            $products = [
                ['1', 'עגילי זהב ויהלומים', ['חישוקים'], [FactStatus::Rejected, 'review_wrong']],
                ['2', 'עגילי וינטג׳ משובצים', [], [FactStatus::Approved, 'code_image']],
                ['3', 'עגילי באגט משובצים', [], [FactStatus::Approved, 'code_description']],
                ['4', 'עגילי חישוק פחוסים', [], [FactStatus::Approved, 'code_title']],
                ['5', 'עגילי פנינה', ['חישוקים'], null],
            ];
            foreach ($products as [$id, $title, $tags, $style]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => 500, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => $tags]]);
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => FactKind::Type, 'key' => 'type',
                    'value_text' => 'earrings', 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "t{$id}"]);
                if ($style !== null) {
                    EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => FactKind::Choice, 'key' => 'earring_style',
                        'value_text' => 'hoops', 'origin' => FactOrigin::Code, 'status' => $style[0], 'status_reason' => $style[1], 'input_hash' => "s{$id}"]);
                }
            }
        });
    }

    public function test_off_the_index_and_the_results_are_as_they_were(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $index = $this->index();
        $record = fn (string $id): array => $index['records']['p:'.$id];

        $this->assertArrayNotHasKey('no', $record('1'));
        $this->assertArrayNotHasKey('ev', $record('2'));
        $this->assertContains('1', $this->found('עגילי חישוק'), 'held by its words, as before');
    }

    public function test_a_value_found_wrong_is_not_held_by_its_words_and_the_surest_come_first(): void
    {
        Settings::set('search.evidence_index', true, $this->shop->id);
        Settings::set('search.evidence_filter', true, $this->shop->id);
        app(BuildSearchIndex::class)->handle($this->shop->id);
        LoadedIndex::forget();
        $index = $this->index();
        $hoops = null;
        foreach ($index['facets'] as $g => $facet) {
            foreach ($facet['v'] as $i => $value) {
                $hoops = ($facet['k'] ?? '') === 'earring_style' && ($value['k'] ?? '') === 'hoops' ? $g * 1000 + $i : $hoops;
            }
        }
        $record = fn (string $id): array => $index['records']['p:'.$id];

        $this->assertSame([$hoops], $record('1')['no'] ?? null, 'the check found it wrong');
        $this->assertSame(Evidence::PICTURE, $record('2')['ev'][$hoops] ?? null);
        $this->assertSame(Evidence::DESCRIPTION, $record('3')['ev'][$hoops] ?? null);
        $this->assertArrayNotHasKey('ev', $record('4'), 'its title says it: as good as stated');

        $found = $this->found('עגילי חישוק');
        $this->assertNotContains('1', $found, 'never by its words once a check found it wrong');
        $this->assertSame('4', $found[0], 'the one its title names first');
        $this->assertSame(['4', '3'], array_slice($found, 0, 2), 'then the one its description says it of');
        $this->assertEqualsCanonicalizing(['2', '5'], array_slice($found, 2), 'then those held by a picture\x27s detail or by their words alone, as they came');
    }

    /** @return array<string, mixed> */
    private function index(): array
    {
        return app(TenantContext::class)->run($this->shop->id, fn (): array => (array) LoadedIndex::for($this->shop->id));
    }

    /** @return list<string> */
    private function found(string $query): array
    {
        return array_column(app(SearchCatalog::class)->handle($this->shop->id, $query, ['product'], false, null, false)['groups']['product'], 'external_id');
    }
}
