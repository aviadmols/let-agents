<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Enums\ColorShade;
use App\Modules\Catalog\Models\CatalogProduct;

/**
 * A product's variants as rows a search can ask "black, size M, in stock" of: each variant with
 * its colour (the store's name, the shades and families it reads as, a swatch), its pattern, its
 * size and where that size stands in the store's own order, its stock and its picture.
 *
 * Code alone. Which option is the colour and which the size is read from the option's name
 * ("צבע", "Color", "pa_size"), and when the name says neither, from its values: options whose
 * values are mostly colours are the colour, mostly letter sizes the size. Numbers alone are not
 * taken for sizes without a name that says so: a blade's 185 and 210 are its diameter.
 */
final class VariantRows
{
    private const COLOR_NAMES = ['צבע', 'צבעים', 'גוון', 'גוונים', 'color', 'colors', 'colour', 'colours', 'shade'];

    private const SIZE_NAMES = ['מידה', 'מידות', 'מידת', 'גודל', 'size', 'sizes'];

    private const PATTERN_NAMES = ['דוגמה', 'דוגמא', 'הדפס', 'pattern', 'print'];

    /** Letter sizes as stores write them, to the one key a filter uses. */
    private const SIZE_KEYS = [
        'XXS' => 'XXS', 'XS' => 'XS', 'S' => 'S', 'SMALL' => 'S', 'M' => 'M', 'MEDIUM' => 'M', 'L' => 'L', 'LARGE' => 'L',
        'XL' => 'XL', 'XLARGE' => 'XL', 'XXL' => 'XXL', '2XL' => 'XXL', 'XXLARGE' => 'XXL', 'XXXL' => 'XXXL', '3XL' => 'XXXL',
        'XXXXL' => '4XL', '4XL' => '4XL', '5XL' => '5XL', 'ONESIZE' => 'ONE', 'OS' => 'ONE', 'FREESIZE' => 'ONE', 'מידהאחת' => 'ONE',
    ];

    /** Share of an option's values that must read as colours (or letter sizes) for an unnamed option to be one. */
    private const BY_VALUES = 0.6;

    /** @return list<array<string, mixed>> the catalog_variants columns of each variant, without ids */
    public static function for(CatalogProduct $product): array
    {
        return self::fromPayload((array) $product->payload);
    }

    /**
     * @param  array<string, mixed>  $payload  a product as the feed sends it
     * @return list<array<string, mixed>>
     */
    public static function fromPayload(array $payload): array
    {
        $variations = array_values(array_filter((array) ($payload['variations'] ?? []), fn ($v): bool => is_array($v)
            // A variation the store switched off (WooCommerce "private") is not for sale.
            && in_array($v['status'] ?? 'publish', ['publish', ''], true)));

        if ($variations === []) {
            return [];
        }

        $attributes = self::productAttributes($payload);
        [$color, $size, $pattern] = self::roles($variations, $attributes);
        // No colour to choose, but the product has one: every variant is that colour ("צבע: שחור", sizes to choose).
        $sharedColor = $color === null ? self::sharedColor($attributes) : null;
        $sizeOrder = $size === null ? [] : self::order($size, $variations, $attributes);
        $swatches = $color === null ? [] : (array) ($attributes[$color]['swatches'] ?? []);

        $rows = [];

        foreach ($variations as $position => $variant) {
            $options = self::options($variant);
            $colorLabel = $color !== null ? ($options[$color]['value'] ?? '') : (string) $sharedColor;
            $sizeLabel = $size !== null ? ($options[$size]['value'] ?? '') : '';
            $read = $colorLabel === '' ? ['shades' => [], 'patterns' => []] : ColorWords::read($colorLabel);
            $hex = ColorWords::hex($swatches[$colorLabel] ?? null);
            $shades = $read['shades'] !== [] ? $read['shades'] : array_filter([$hex === null ? null : ColorWords::nearest($hex)]);
            $patterns = $read['patterns'];

            if ($patterns === [] && $pattern !== null && ($options[$pattern]['value'] ?? '') !== '') {
                $patterns = ColorWords::read($options[$pattern]['value'])['patterns'];
            }

            $families = [];
            foreach ($shades as $shade) {
                foreach ($shade->families() as $family) {
                    $families[$family->value] = $family->value;
                }
            }

            $others = [];
            foreach ($options as $id => $option) {
                if (! in_array($id, [$color, $size], true) && $option['value'] !== '') {
                    $others[$option['name']] = $option['value'];
                }
            }

            $stock = (array) ($variant['stock'] ?? []);
            $price = $variant['price']['price'] ?? null;
            $quantity = $stock['quantity'] ?? null;

            $rows[] = [
                'external_id' => (string) ($variant['external_id'] ?? ''),
                'position' => $position,
                'title' => mb_substr(CatalogProduct::variantLabel($variant), 0, 255),
                'sku' => self::nullable($variant['sku'] ?? null, 120),
                'color_label' => self::nullable($colorLabel, 120),
                'color_shades' => $shades === [] ? null : array_values(array_map(fn (ColorShade $s): string => $s->value, $shades)),
                'color_families' => $families === [] ? null : array_values($families),
                'color_hex' => $hex ?? (isset($shades[0]) ? $shades[0]->hex() : null),
                'pattern' => isset($patterns[0]) ? $patterns[0]->value : null,
                'size_label' => self::nullable($sizeLabel, 60),
                'size_key' => $sizeLabel === '' ? null : self::sizeKey($sizeLabel),
                'size_position' => $sizeLabel === '' ? null : ($sizeOrder[$sizeLabel] ?? null),
                'options' => $others,
                'price' => is_numeric($price) ? number_format((float) $price, 2, '.', '') : null,
                'in_stock' => (bool) ($stock['in_stock'] ?? false),
                // A count only where the store keeps one; Shopify tells "can be bought", not how many.
                'quantity' => ($stock['managed'] ?? false) && is_numeric($quantity) ? (int) $quantity : null,
                'purchasable' => (bool) ($variant['purchasable'] ?? ($stock['in_stock'] ?? false)),
                'image_url' => self::nullable($variant['image'] ?? null),
            ];
        }

        return array_values(array_filter($rows, fn (array $row): bool => $row['external_id'] !== ''));
    }

    /** "2XL" and "XX-Large" are "XXL"; "38" stays "38"; anything else as the store wrote it, upper case. */
    public static function sizeKey(string $label): string
    {
        $compact = (string) preg_replace('/[\s\-_.]+/u', '', mb_strtoupper(trim($label)));

        return mb_substr(self::SIZE_KEYS[$compact] ?? $compact, 0, 20);
    }

    /**
     * The product's own options by their id (key, else name), with their values in the store's order.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array{name: string, values: list<string>, swatches: array<string, string>, for_variations: bool}>
     */
    private static function productAttributes(array $payload): array
    {
        $out = [];

        foreach ((array) ($payload['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $name = trim((string) ($attribute['name'] ?? ''));
            $id = trim((string) ($attribute['key'] ?? '')) ?: $name;

            if ($id === '') {
                continue;
            }

            $out[$id] = [
                'name' => $name !== '' ? $name : $id,
                'values' => array_values(array_filter(array_map(fn ($v): string => trim((string) $v), (array) ($attribute['values'] ?? [])), fn (string $v): bool => $v !== '')),
                'swatches' => array_filter((array) ($attribute['swatches'] ?? []), 'is_string'),
                'for_variations' => (bool) ($attribute['used_for_variations'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * A variant's chosen value of each option, by the option's id.
     *
     * @param  array<string, mixed>  $variant
     * @return array<string, array{name: string, value: string}>
     */
    private static function options(array $variant): array
    {
        $out = [];

        foreach ((array) ($variant['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $name = trim((string) ($attribute['name'] ?? ''));
            $id = trim((string) ($attribute['key'] ?? '')) ?: $name;

            if ($id !== '') {
                // An empty value is "any": the shopper picks it on the page.
                $out[$id] = ['name' => $name !== '' ? $name : $id, 'value' => trim((string) ($attribute['value'] ?? ''))];
            }
        }

        return $out;
    }

    /**
     * Which option is the colour, which the size and which the pattern: by name first, then by values.
     *
     * @param  list<array<string, mixed>>  $variations
     * @param  array<string, array{name: string, values: list<string>}>  $attributes
     * @return array{0: string|null, 1: string|null, 2: string|null} option ids
     */
    private static function roles(array $variations, array $attributes): array
    {
        $values = [];
        $names = [];
        foreach ($variations as $variant) {
            foreach (self::options($variant) as $id => $option) {
                $names[$id] = $option['name'];
                if ($option['value'] !== '') {
                    $values[$id][$option['value']] = true;
                }
            }
        }

        $roles = ['color' => null, 'size' => null, 'pattern' => null];

        foreach ($names as $id => $name) {
            $role = match (true) {
                self::named($id.' '.$name, self::COLOR_NAMES) => 'color',
                self::named($id.' '.$name, self::SIZE_NAMES) => 'size',
                self::named($id.' '.$name, self::PATTERN_NAMES) => 'pattern',
                default => null,
            };
            if ($role !== null) {
                $roles[$role] ??= $id;
            }
        }

        foreach ($names as $id => $name) {
            if (in_array($id, $roles, true)) {
                continue;
            }
            $list = array_keys($values[$id] ?? []);
            if ($list === []) {
                continue;
            }
            if ($roles['color'] === null && self::share($list, fn (string $v): bool => ColorWords::knows($v)) >= self::BY_VALUES) {
                $roles['color'] = $id;
            } elseif ($roles['size'] === null && self::share($list, fn (string $v): bool => in_array(self::sizeKey($v), self::SIZE_KEYS, true)) >= self::BY_VALUES) {
                $roles['size'] = $id;
            }
        }

        return [$roles['color'], $roles['size'], $roles['pattern']];
    }

    /**
     * The one colour a product has when no option chooses it: a colour attribute with a single value.
     *
     * @param  array<string, array{name: string, values: list<string>, for_variations: bool}>  $attributes
     */
    private static function sharedColor(array $attributes): ?string
    {
        foreach ($attributes as $id => $attribute) {
            if (! $attribute['for_variations'] && count($attribute['values']) === 1 && self::named($id.' '.$attribute['name'], self::COLOR_NAMES)) {
                return $attribute['values'][0];
            }
        }

        return null;
    }

    /**
     * Where each size stands: in the store's own list of the option's values (stores list S before M),
     * else in the order the variants come.
     *
     * @param  list<array<string, mixed>>  $variations
     * @param  array<string, array{name: string, values: list<string>}>  $attributes
     * @return array<string, int>
     */
    private static function order(string $id, array $variations, array $attributes): array
    {
        $list = $attributes[$id]['values'] ?? [];

        foreach ($attributes as $attribute) {
            if ($list === [] && $attribute['name'] === $id) {
                $list = $attribute['values'];
            }
        }

        foreach ($variations as $variant) {
            $value = self::options($variant)[$id]['value'] ?? '';
            if ($value !== '' && ! in_array($value, $list, true)) {
                $list[] = $value;
            }
        }

        return array_flip(array_values($list));
    }

    /** Whether the option's name says it, as a word of its own ("בחרו צבע", "pa_color", "הצבע"). */
    private static function named(string $name, array $words): bool
    {
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (in_array($token, $words, true) || (preg_match('/^[הבו]/u', $token) === 1 && in_array(mb_substr($token, 1), $words, true))) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $values */
    private static function share(array $values, callable $test): float
    {
        return count(array_filter($values, $test)) / count($values);
    }

    private static function nullable(mixed $value, int $max = 0): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return $max > 0 ? mb_substr(trim((string) $value), 0, $max) : trim((string) $value);
    }
}
