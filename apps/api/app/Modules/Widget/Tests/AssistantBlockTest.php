<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Actions\BuildPageBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The block under the add-to-cart: the shop's assistant by name, the product's facts as tags
 * (each searching for others like it), and questions worth asking, built in code.
 */
final class AssistantBlockTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'Rocks and Gold']);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            $vocabulary = EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'jewelry', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'jewelry', 'name' => ['he' => 'תכשיטים', 'en' => 'Jewelry'], 'root_category_external_id' => '*',
                    'product_types' => [['key' => 'pendant', 'label' => ['he' => 'תליון', 'en' => 'Pendant']]],
                    'attributes' => [
                        ['key' => 'metal', 'type' => 'enum', 'label' => ['he' => 'מתכת', 'en' => 'Metal'], 'values' => [
                            ['key' => 'yellow_gold', 'label' => ['he' => 'זהב צהוב', 'en' => 'Yellow gold']], ['key' => 'white_gold', 'label' => ['he' => 'זהב לבן', 'en' => 'White gold']]]],
                        ['key' => 'gold_karat', 'type' => 'enum', 'label' => ['he' => 'קראטים', 'en' => 'Karat'], 'values' => [
                            ['key' => 'k14', 'label' => ['he' => '14K', 'en' => '14K']], ['key' => 'k18', 'label' => ['he' => '18K', 'en' => '18K']]]],
                    ]]]);
            foreach (['1' => ['yellow_gold', 'k14'], '2' => ['white_gold', 'k18'], '3' => ['white_gold', 'k18']] as $id => [$metal, $karat]) {
                $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "תליון {$id}",
                    'url' => "https://store.test/p/{$id}", 'price' => 900, 'in_stock' => true, 'purchasable' => true, 'hash' => "h{$id}", 'payload' => []]);
                foreach ([[FactKind::Type, 'type', 'pendant'], [FactKind::Choice, 'metal', $metal], [FactKind::Choice, 'gold_karat', $karat]] as $i => [$kind, $key, $value]) {
                    EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $vocabulary->id, 'kind' => $kind, 'key' => $key,
                        'value_text' => $value, 'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => "f{$id}{$i}"]);
                }
            }
        });
        Features::override('widget.on_products', true, $this->shop->id);
        Features::override('assistant.on_products', true, $this->shop->id);
    }

    public function test_the_block_carries_the_products_tags_and_questions_in_its_layout_only(): void
    {
        Settings::set('widget.layout', 'assistant', $this->shop->id);

        $block = app(BuildPageBank::class)->handle($this->shop->id, 'product', '1', 'he')['assistant'];

        $this->assertSame('שאלו את Rocks and Gold AI', $block['title']);
        $this->assertSame(['id' => '1', 'title' => 'תליון 1'], $block['product']);
        $this->assertSame([['label' => 'תליון', 'query' => 'תליון'], ['label' => 'זהב צהוב', 'query' => 'תליון זהב צהוב'], ['label' => '14K', 'query' => 'תליון 14K']], $block['tags']);
        // Next steps that lead somewhere: similar items, a comparison (named in the browser), more of its type,
        // another value of its choices, a cheaper one; returns and delivery only where the store says something.
        $this->assertSame(['text' => 'לראות פריטים דומים', 'about' => 'similar_items'], $block['questions'][0]);
        $this->assertSame('compare', $block['questions'][1]['about']);
        $this->assertSame('להשוות עם :title', $block['questions'][1]['named']);
        $this->assertSame(['text' => 'עוד 2 תליון', 'about' => 'similar', 'query' => 'תליון'], $block['questions'][2], 'the other products of its type');
        $this->assertSame(['text' => 'יש תליון דומה בזהב לבן?', 'about' => 'similar', 'query' => 'תליון זהב לבן'], $block['questions'][3], 'a value of its choice the store also sells, as a search');
        $this->assertSame(['text' => 'יש תליון דומה במחיר נמוך יותר?', 'about' => 'similar', 'query' => 'תליון עד 899'], $block['questions'][4], 'cheaper than this one, as a price range');
        $this->assertNotContains('אפשר להחזיר או להחליף?', array_column($block['questions'], 'text'), 'the store says nothing about returns: not asked');

        // A refund policy of its own: now the question has an answer.
        app(TenantContext::class)->run($this->shop->id, fn () => CatalogContent::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'policy-1', 'type' => 'page', 'status' => 'publish',
            'title' => 'מדיניות החזרות', 'slug' => 'refund_policy', 'url' => 'https://store.test/policies/refund-policy', 'hash' => 'p1', 'payload' => []]));
        $again = app(BuildPageBank::class)->handle($this->shop->id, 'product', '1', 'he')['assistant'];
        $this->assertContains('אפשר להחזיר או להחליף?', array_column($again['questions'], 'text'));

        Settings::set('widget.layout', 'circles', $this->shop->id);
        $this->assertNull(app(BuildPageBank::class)->handle($this->shop->id, 'product', '1', 'he')['assistant']);
    }
}
