<?php

namespace App\Modules\Catalog\Tests;

use App\Modules\Catalog\Support\VariantRows;
use PHPUnit\Framework\TestCase;

final class VariantRowsTest extends TestCase
{
    public function test_a_shirt_in_colours_and_sizes_has_a_row_for_each_with_its_own_stock(): void
    {
        $rows = collect(VariantRows::fromPayload($this->shirt()))->keyBy('external_id');

        $this->assertCount(4, $rows, 'the variation the store switched off is not for sale');

        $navyM = $rows['101'];
        $this->assertSame(['כחול כהה', ['navy'], ['blue'], '#1B2A4A', null], [$navyM['color_label'], $navyM['color_shades'], $navyM['color_families'], $navyM['color_hex'], $navyM['pattern']]);
        $this->assertSame(['M', 'M', 1], [$navyM['size_label'], $navyM['size_key'], $navyM['size_position']], 'M stands where the store lists it: after S');
        $this->assertSame([true, 3, '129.90', 'https://store.test/navy.jpg'], [$navyM['in_stock'], $navyM['quantity'], $navyM['price'], $navyM['image_url']]);
        $this->assertSame(['אורך שרוול' => 'ארוך'], $navyM['options'], 'other options are kept by name');

        $navyL = $rows['102'];
        $this->assertFalse($navyL['in_stock'], 'navy in L is sold out, though navy in M is not');
        $this->assertNull($navyL['quantity'], 'a store that does not count has no count');

        $striped = $rows['103'];
        $this->assertSame([['blue', 'white'], 'stripes'], [$striped['color_families'], $striped['pattern']], 'a striped variant is its colours, and striped');

        $anyColor = $rows['105'];
        $this->assertNull($anyColor['color_label'], 'an option left open is chosen by the shopper');
        $this->assertSame('XXL', $anyColor['size_key'], '2XL and XXL are one size');
    }

    public function test_an_option_whose_name_says_nothing_is_read_by_its_values(): void
    {
        $rows = VariantRows::fromPayload(['variations' => [
            $this->variant('1', ['גרסה' => 'Black', 'בחירה' => 'S']),
            $this->variant('2', ['גרסה' => 'Ivory', 'בחירה' => 'M']),
        ]]);

        $this->assertSame(['Black', 'S'], [$rows[0]['color_label'], $rows[0]['size_label']]);
        $this->assertSame(['off_white'], $rows[1]['color_shades']);
    }

    public function test_numbers_alone_are_not_taken_for_sizes(): void
    {
        // A blade's 185 and 210 are its diameter.
        $rows = VariantRows::fromPayload(['variations' => [
            $this->variant('1', ['קוטר להב' => '185']),
            $this->variant('2', ['קוטר להב' => '210']),
        ]]);

        $this->assertNull($rows[0]['size_label']);
        $this->assertSame(['קוטר להב' => '185'], $rows[0]['options']);
    }

    public function test_a_colour_the_product_has_but_does_not_offer_to_choose_is_every_variant_colour(): void
    {
        $rows = VariantRows::fromPayload([
            'attributes' => [
                ['key' => 'pa_color', 'name' => 'צבע', 'values' => ['שחור'], 'used_for_variations' => false],
                ['key' => 'pa_size', 'name' => 'מידה', 'values' => ['S', 'M'], 'used_for_variations' => true],
            ],
            'variations' => [
                $this->variant('1', ['pa_size' => 'S']),
                $this->variant('2', ['pa_size' => 'M']),
            ],
        ]);

        $this->assertSame([['black'], ['black']], array_column($rows, 'color_shades'));
    }

    public function test_a_colour_name_nobody_knows_reads_by_its_swatch(): void
    {
        $rows = VariantRows::fromPayload([
            'attributes' => [['key' => 'Color', 'name' => 'Color', 'values' => ['Ocean Mist'], 'swatches' => ['Ocean Mist' => '#1c2b4b'], 'used_for_variations' => true]],
            'variations' => [$this->variant('1', ['Color' => 'Ocean Mist'])],
        ]);

        $this->assertSame([['navy'], ['blue'], '#1C2B4B'], [$rows[0]['color_shades'], $rows[0]['color_families'], $rows[0]['color_hex']], 'the store\x27s own swatch is kept');

        $unknown = VariantRows::fromPayload(['variations' => [$this->variant('1', ['Color' => 'Ocean Mist'])]]);
        $this->assertSame(['Ocean Mist', null, null], [$unknown[0]['color_label'], $unknown[0]['color_shades'], $unknown[0]['color_families']], 'kept by its name, for a person or a model to place');
    }

    public function test_a_product_without_variations_has_no_rows(): void
    {
        $this->assertSame([], VariantRows::fromPayload(['variations' => []]));
    }

    /** @return array<string, mixed> a WooCommerce shirt: colour, size and sleeve, one variation off, one colour left open */
    private function shirt(): array
    {
        return [
            'attributes' => [
                ['key' => 'pa_color', 'name' => 'צבע', 'values' => ['כחול כהה', 'פסים כחול-לבן'], 'used_for_variations' => true],
                ['key' => 'pa_size', 'name' => 'מידה', 'values' => ['S', 'M', 'L', '2XL'], 'used_for_variations' => true],
                ['key' => 'sleeve', 'name' => 'אורך שרוול', 'values' => ['ארוך'], 'used_for_variations' => true],
            ],
            'variations' => [
                $this->variant('101', ['pa_color' => 'כחול כהה', 'pa_size' => 'M', 'sleeve' => 'ארוך'], ['in_stock' => true, 'managed' => true, 'quantity' => 3], 'https://store.test/navy.jpg'),
                $this->variant('102', ['pa_color' => 'כחול כהה', 'pa_size' => 'L', 'sleeve' => 'ארוך'], ['in_stock' => false, 'managed' => false, 'quantity' => null]),
                $this->variant('103', ['pa_color' => 'פסים כחול-לבן', 'pa_size' => 'M', 'sleeve' => 'ארוך']),
                ['status' => 'private'] + $this->variant('104', ['pa_color' => 'פסים כחול-לבן', 'pa_size' => 'L', 'sleeve' => 'ארוך']),
                $this->variant('105', ['pa_color' => '', 'pa_size' => '2XL', 'sleeve' => 'ארוך']),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $options  option key => value
     * @param  array<string, mixed>  $stock
     * @return array<string, mixed>
     */
    private function variant(string $id, array $options, array $stock = ['in_stock' => true], string $image = ''): array
    {
        $names = ['pa_color' => 'צבע', 'pa_size' => 'מידה', 'sleeve' => 'אורך שרוול'];

        return [
            'external_id' => $id,
            'status' => 'publish',
            'sku' => "SH-{$id}",
            'attributes' => array_map(fn (string $key, string $value): array => ['key' => $key, 'name' => $names[$key] ?? $key, 'value' => $value], array_keys($options), array_values($options)),
            'price' => ['price' => '129.9', 'regular_price' => '129.9', 'on_sale' => false],
            'stock' => $stock,
            'purchasable' => (bool) ($stock['in_stock'] ?? false),
            'image' => $image,
        ];
    }
}
