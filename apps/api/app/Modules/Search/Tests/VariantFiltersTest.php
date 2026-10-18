<?php

namespace App\Modules\Search\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Actions\BuildProductVariants;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Search\Support\LoadedIndex;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Colour and size as filters, read from products' variants: the families a shopper picks from, the
 * sizes in the order sizes go, and each product's variants as the codes one variant has with its
 * stock, so "black, M, in stock" is asked of one variant.
 */
final class VariantFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        LoadedIndex::forget();
        $this->shop = Shop::factory()->create(['content_locale' => 'he']);

        $this->inShop(function (): void {
            // The vocabulary's own colour: where the variants say the colours, not a second colour filter.
            EnrichmentVocabulary::query()->create(['shop_id' => $this->shop->id, 'key' => 'apparel', 'version' => 1, 'root_category_external_id' => '*', 'active' => true, 'definition_hash' => 'h',
                'definition' => ['schema_version' => 1, 'key' => 'apparel', 'name' => ['he' => 'ביגוד', 'en' => 'Apparel'], 'root_category_external_id' => '*',
                    'product_types' => [['key' => 'shirt', 'label' => ['he' => 'חולצה', 'en' => 'Shirt']]],
                    'attributes' => [['key' => 'color', 'type' => 'enum', 'label' => ['he' => 'צבע', 'en' => 'Colour'], 'values' => [['key' => 'black', 'label' => ['he' => 'שחור', 'en' => 'Black']]]]],
                ]]);

            $variant = fn (string $id, string $color, string $size, bool $inStock): array => [
                'external_id' => $id, 'status' => 'publish',
                'attributes' => [['key' => 'pa_color', 'name' => 'צבע', 'value' => $color], ['key' => 'pa_size', 'name' => 'מידה', 'value' => $size]],
                'price' => ['price' => '99'], 'stock' => ['in_stock' => $inStock], 'purchasable' => $inStock,
            ];
            $shirt = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '1', 'type' => 'variable', 'status' => 'publish', 'title' => 'חולצת טריקו',
                'url' => 'https://store.test/p/1', 'price' => 99, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h1', 'variations_count' => 3, 'payload' => [
                    'attributes' => [['key' => 'pa_size', 'name' => 'מידה', 'values' => ['S', 'M'], 'used_for_variations' => true]],
                    'variations' => [$variant('11', 'שחור', 'S', true), $variant('12', 'שחור', 'M', false), $variant('13', 'נייבי', 'M', true)],
                ]]);
            CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => '2', 'type' => 'simple', 'status' => 'publish', 'title' => 'כובע',
                'url' => 'https://store.test/p/2', 'price' => 49, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h2', 'payload' => []]);
            app(BuildProductVariants::class)->product($shirt);
        });
    }

    public function test_colour_and_size_are_filters_and_each_variant_carries_its_codes_and_stock(): void
    {
        app(BuildSearchIndex::class)->handle($this->shop->id);
        $data = json_decode((string) $this->inShop(fn () => SearchIndex::query()->sole()->items), true);

        $this->assertSame(['type', 'variant_color', 'variant_size'], array_column($data['facets'], 'k'), 'the vocabulary\'s colour is not a second colour filter');
        $colour = $data['facets'][1];
        $this->assertSame([['k' => 'black', 'l' => 'שחור', 'h' => '#111111'], ['k' => 'blue', 'l' => 'כחול', 'h' => '#1F5FBF']], $colour['v'], 'navy is blue to a shopper');
        $this->assertSame(['S', 'M'], array_column($data['facets'][2]['v'], 'l'));

        $shirt = collect($data['items'])->firstWhere('id', 'p:1');
        [$black, $blue, $s, $m] = [1000, 1001, 2000, 2001];
        $this->assertSame([$black, $blue, $s, $m], $shirt['f']);
        $this->assertSame([[[$black, $s], 1], [[$black, $m], 0], [[$blue, $m], 1]], $shirt['v'], 'black in M is sold out though black and M are each there');
        $this->assertArrayNotHasKey('v', collect($data['items'])->firstWhere('id', 'p:2'), 'a product without variants has none');
    }

    public function test_sizes_go_in_the_order_sizes_go(): void
    {
        $this->assertSame(['XS', 'S', 'M', 'XL', 'XXL', 'ONE', '38', '40', '42.5', 'Petite'], BuildSearchIndex::sizeOrder(['XL', '40', 'M', 'Petite', 'ONE', '38', 'XS', 'XXL', '42.5', 'S']));
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
