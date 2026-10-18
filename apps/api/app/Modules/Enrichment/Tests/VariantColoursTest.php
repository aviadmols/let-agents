<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Catalog\Actions\BuildProductVariants;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A garment sold in several colours has them all, from its variants. Before, the colour option
 * listed in the store's fields named several, a one-colour attribute refused them, and the product
 * had no colour at all.
 */
final class VariantColoursTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_a_shirt_sold_in_several_colours_has_every_one_and_its_pattern(): void
    {
        $this->buildShop();
        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        $shirts = $this->category('5', 'חולצות');
        $this->inShop(fn () => EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'apparel', 'version' => 1, 'root_category_external_id' => '5', 'active' => true, 'definition_hash' => 'h',
            'definition' => ['schema_version' => 1, 'key' => 'apparel', 'name' => ['he' => 'ביגוד', 'en' => 'Apparel'], 'root_category_external_id' => '5',
                'product_types' => [['key' => 'shirt', 'label' => ['he' => 'חולצה', 'en' => 'Shirt'], 'categories' => ['5']]],
                'attributes' => [
                    // One colour a product, as a model wrote it: the store's words would refuse several.
                    ['key' => 'color', 'type' => 'enum', 'label' => ['he' => 'צבע', 'en' => 'Colour'], 'values' => [
                        ['key' => 'black', 'label' => ['he' => 'שחור', 'en' => 'Black'], 'patterns' => ['שחור']],
                        ['key' => 'navy', 'label' => ['he' => 'נייבי', 'en' => 'Navy'], 'patterns' => ['נייבי', 'כחול כהה']],
                        ['key' => 'white', 'label' => ['he' => 'לבן', 'en' => 'White'], 'patterns' => ['לבן']],
                        ['key' => 'blue', 'label' => ['he' => 'כחול', 'en' => 'Blue'], 'patterns' => ['כחול']],
                    ]],
                    ['key' => 'pattern', 'type' => 'enum', 'label' => ['he' => 'דוגמה', 'en' => 'Pattern'], 'values' => [
                        ['key' => 'stripes', 'label' => ['he' => 'פסים', 'en' => 'Stripes']], ['key' => 'solid', 'label' => ['he' => 'חלק', 'en' => 'Solid']],
                    ]],
                ]]]));

        $variant = fn (string $id, string $color, string $size): array => ['external_id' => $id, 'status' => 'publish',
            'attributes' => [['key' => 'pa_color', 'name' => 'צבע', 'value' => $color], ['key' => 'pa_size', 'name' => 'מידה', 'value' => $size]], 'stock' => ['in_stock' => true]];
        $shirt = $this->product('1', 'חולצת טריקו', '', [$shirts], ['type' => 'variable', 'variations_count' => 3, 'payload' => [
            'attributes' => [['key' => 'pa_color', 'name' => 'צבע', 'values' => ['שחור', 'כחול כהה', 'פסים כחול-לבן'], 'used_for_variations' => true]],
            'variations' => [$variant('11', 'שחור', 'M'), $variant('12', 'כחול כהה', 'M'), $variant('13', 'פסים כחול-לבן', 'L')],
        ]]);
        $this->inShop(fn () => app(BuildProductVariants::class)->product($shirt));

        app(ReadProductsInCode::class)->handle($this->shop->id);

        $facts = $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $shirt->id)->where('kind', FactKind::Choice)->where('status', FactStatus::Approved)->get());
        $this->assertSame(['black', 'navy', 'blue', 'white'], $facts->where('key', 'color')->pluck('value_text')->values()->all(), 'each colour it comes in: "כחול כהה" is the shop\'s navy, the striped one is blue and white');
        $this->assertSame(['stripes'], $facts->where('key', 'pattern')->pluck('value_text')->values()->all());
        $this->assertSame(['code_variant'], $facts->pluck('status_reason')->unique()->values()->all(), 'from the variants, not refused by the words');
    }
}
