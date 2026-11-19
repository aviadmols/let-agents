<?php

namespace App\Modules\Search\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentTypeAudit;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The search follows the audited type (Vans, 19.11): a category or a tag that names another of the store's types than the
 * product's own settled type is none of the product's words. A mesh top the audit found to be no type of the bank keeps
 * neither "חולצות טי" nor "חולצות מכופתרות"; a button shirt mis-tagged "חולצות טי" keeps its own tag only; a product
 * whose type is not settled changes nothing. The type filter follows the facts as it did.
 */
final class AuditedTypeWordsTest extends TestCase
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
            $vocabulary = EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'tops', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'tops', 'name' => ['he' => 'חולצות', 'en' => 'Tops'], 'root_category_external_id' => '*',
                    'product_types' => [
                        ['key' => 'tshirt', 'label' => ['he' => 'חולצות טי', 'en' => 'T-shirts'], 'patterns' => ['חולצות[\s\-]+טי', 'חולצת[\s\-]+טי']],
                        ['key' => 'shirt', 'label' => ['he' => 'חולצות מכופתרות', 'en' => 'Button shirts'], 'patterns' => ['חולצות[\s\-]+מכופתרות', 'מכופתרת']],
                    ],
                    'attributes' => []]]);
            $joined = CatalogCategory::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'c1', 'name' => 'חולצות טי ומכופתרות', 'path' => ['חולצות טי ומכופתרות'], 'depth' => 0, 'hash' => 'c1']);
            // id, title, tags, the settled type: a type fact's status and value, and whether the audit took the type off
            $products = [
                ['1', 'חולצת רשת ארוכה Ludlow Mesh', ['חולצות טי', 'חולצות מכופתרות'], [FactStatus::Rejected, 'tshirt'], true],
                ['2', 'חולצת Oxford לבנה', ['חולצות מכופתרות', 'חולצות טי'], [FactStatus::Approved, 'shirt'], false],
                ['3', 'חולצת טי לוגו Classic', ['חולצות טי'], [FactStatus::Approved, 'tshirt'], false],
                ['4', 'כובע Classic', ['חולצות טי'], null, false],
            ];
            foreach ($products as [$id, $title, $tags, $type, $removed]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                    'url' => "https://store.test/p/{$id}", 'price' => 200, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => ['tags' => $tags]]);
                $product->categories()->attach([$joined->id]);
                if ($type !== null) {
                    EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => FactKind::Type, 'key' => 'type',
                        'value_text' => $type[1], 'origin' => FactOrigin::Code, 'status' => $type[0], 'status_reason' => $type[0] === FactStatus::Rejected ? 'audit_none' : 'code_field', 'input_hash' => "t{$id}"]);
                }
                if ($removed) {
                    EnrichmentTypeAudit::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'fingerprint' => str_repeat($id, 64), 'code_type' => 'tshirt',
                        'type' => EnrichmentTypeAudit::NONE, 'confidence' => 96, 'why' => 'a mesh top', 'model' => 'gpt-5.4-mini', 'status' => EnrichmentTypeAudit::REMOVED, 'answered_at' => now()]);
                }
            }
        });
        app(BuildSearchIndex::class)->handle($this->shop->id);
    }

    public function test_a_name_that_says_another_type_than_the_products_settled_type_is_none_of_its_words(): void
    {
        $items = $this->items();

        // the mesh top: no type of the bank, so neither tag is its word; its own title is what it is
        $this->assertStringNotContainsString('מכופתר', $items['p:1']['kw']);
        $this->assertStringNotContainsString('טי', $items['p:1']['kw']);
        $this->assertSame('חולצת רשת ארוכה Ludlow Mesh', $items['p:1']['title']);

        // the button shirt keeps its own tag and loses the wrong one
        $this->assertStringContainsString('חולצות מכופתרות', $items['p:2']['kw']);
        $this->assertStringNotContainsString('חולצות טי', $items['p:2']['kw']);

        // the tee keeps its tag
        $this->assertStringContainsString('חולצות טי', $items['p:3']['kw']);

        // a product whose type is not settled: nothing changes, the store's tag stays
        $this->assertStringContainsString('חולצות טי', $items['p:4']['kw']);
    }

    public function test_the_type_filter_follows_the_facts(): void
    {
        $data = json_decode((string) app(TenantContext::class)->run($this->shop->id, fn () => SearchIndex::query()->sole()->items), true);
        $at = (int) array_search('type', array_column($data['facets'], 'k'), true);
        $codes = [];
        foreach ($data['facets'][$at]['v'] as $i => $value) {
            $codes[$value['k']] = $at * 1000 + $i;
        }
        $items = $this->items();

        $this->assertNotContains($codes['tshirt'], $items['p:1']['f'] ?? [], 'the mesh top is in no type');
        $this->assertNotContains($codes['shirt'], $items['p:1']['f'] ?? []);
        $this->assertContains($codes['shirt'], $items['p:2']['f']);
        $this->assertContains($codes['tshirt'], $items['p:3']['f']);
    }

    /** @return array<string, array<string, mixed>> the index's items, by id */
    private function items(): array
    {
        return collect(json_decode((string) app(TenantContext::class)->run($this->shop->id, fn () => SearchIndex::query()->sole()->items), true)['items'])->keyBy('id')->all();
    }
}
