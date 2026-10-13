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
        Features::override('shortlist.compare', true, $this->shop->id);
    }

    public function test_the_block_carries_the_products_tags_and_questions_in_its_layout_only(): void
    {
        Settings::set('widget.layout', 'assistant', $this->shop->id);

        $block = app(BuildPageBank::class)->handle($this->shop->id, 'product', '1', 'he')['assistant'];

        $this->assertSame('שאלו את Rocks and Gold AI', $block['title']);
        $this->assertSame(['id' => '1', 'title' => 'תליון 1', 'kinds' => ['fact:pendant']], $block['product'], 'its kind, for a comparison only with another pendant');
        $this->assertSame([['label' => 'תליון', 'query' => 'תליון'], ['label' => 'זהב צהוב', 'query' => 'תליון זהב צהוב'], ['label' => '14K', 'query' => 'תליון 14K']], $block['tags']);
        // Next steps that lead somewhere: similar items, a comparison (named in the browser), more of its type,
        // another value of its choices; returns and delivery only where the store says something.
        $this->assertSame(['text' => 'לראות פריטים דומים', 'about' => 'similar_items'], $block['questions'][0]);
        $this->assertSame('compare', $block['questions'][1]['about']);
        $this->assertSame('להשוות עם :title', $block['questions'][1]['named']);
        $this->assertSame(['text' => 'עוד 2 תליון', 'about' => 'similar', 'query' => 'תליון'], $block['questions'][2], 'the other products of its type');
        $this->assertSame(['text' => 'יש תליון דומה בזהב לבן?', 'about' => 'similar', 'query' => 'תליון זהב לבן'], $block['questions'][3], 'a value of its choice the store also sells, as a search');
        $this->assertCount(4, $block['questions'], 'nothing more to offer');
        $this->assertNotContains('אפשר להחזיר או להחליף?', array_column($block['questions'], 'text'), 'the store says nothing about returns: not asked');

        // A refund policy of its own: now the question has an answer.
        app(TenantContext::class)->run($this->shop->id, fn () => CatalogContent::query()->create(['shop_id' => $this->shop->id, 'external_id' => 'policy-1', 'type' => 'page', 'status' => 'publish',
            'title' => 'מדיניות', 'url' => 'https://store.test/policies/refund-policy', 'hash' => 'p1']));
        $again = app(BuildPageBank::class)->handle($this->shop->id, 'product', '1', 'he')['assistant'];
        $this->assertContains('אפשר להחזיר או להחליף?', array_column($again['questions'], 'text'));

        Settings::set('widget.layout', 'circles', $this->shop->id);
        $this->assertNull(app(BuildPageBank::class)->handle($this->shop->id, 'product', '1', 'he')['assistant']);
    }

    public function test_similar_items_and_the_comparison_only_among_its_own_kind_and_its_series_where_others_share_it(): void
    {
        Settings::set('widget.layout', 'assistant', $this->shop->id);
        app(TenantContext::class)->run($this->shop->id, function (): void {
            // A Shopify store with tags only: "כל המוצרים" on every product says nothing of kind.
            // "sale 20%" is a campaign both are in, not a kind they share.
            foreach (['11' => ['כל המוצרים', 'חיתולי במבוק', 'אייקוני - כדורים פורחים', 'sale 20%'], '12' => ['כל המוצרים', 'חיתולי במבוק'], '13' => ['כל המוצרים', 'שמיכות', 'אייקוני - כדורים פורחים'],
                '14' => ['כל המוצרים', 'טיפוח לתינוק', 'sale 20%'], '15' => ['כל המוצרים', 'שמיכות']] as $id => $tags) {
                CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $id, 'type' => 'simple', 'status' => 'publish', 'title' => "מוצר {$id}",
                    'url' => "https://store.test/p/{$id}", 'price' => 50, 'in_stock' => true, 'purchasable' => true, 'hash' => "t{$id}", 'payload' => ['tags' => $tags]]);
            }
        });

        $swaddle = app(BuildPageBank::class)->handle($this->shop->id, 'product', '11', 'he')['assistant'];
        $about = array_column($swaddle['questions'], 'about');
        $this->assertContains('similar_items', $about, 'other swaddles');
        $this->assertContains('compare', $about);
        $this->assertContains(['text' => 'עוד מוצרים מ"אייקוני - כדורים פורחים"', 'about' => 'similar', 'query' => 'אייקוני - כדורים פורחים'], $swaddle['questions'], 'its print, which a blanket shares');
        $this->assertNotContains('כל המוצרים', array_map(fn (string $k): string => substr($k, 4), $swaddle['product']['kinds']));

        $cream = app(BuildPageBank::class)->handle($this->shop->id, 'product', '14', 'he')['assistant'];
        $this->assertNotContains('similar_items', array_column($cream['questions'], 'about'), 'no other cream: no similar items');
        $this->assertSame([], array_intersect($cream['product']['kinds'], $swaddle['product']['kinds']), 'never compared with a swaddle');
    }
}
