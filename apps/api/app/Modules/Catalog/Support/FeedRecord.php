<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Turns feed records into catalog columns. Pure functions, so the mapping is tested without a
 * store or a database.
 */
final class FeedRecord
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function category(array $record): array
    {
        $path = array_values(array_map('strval', (array) ($record['path'] ?? [])));

        return [
            'external_id' => (string) $record['external_id'],
            'parent_external_id' => self::nullableString($record['parent_id'] ?? null),
            'name' => self::limit((string) ($record['name'] ?? ''), 255),
            'path' => $path,
            'depth' => max(0, count($path) - 1),
            'product_count' => (int) ($record['product_count'] ?? 0),
            'url' => self::nullableString($record['url'] ?? null),
            'image_url' => self::nullableString($record['image'] ?? null),
            'hash' => (string) ($record['hash'] ?? hash('sha256', (string) json_encode($record))),
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function product(array $record): array
    {
        $payload = self::canonicalProduct($record);

        $price = (array) ($record['price'] ?? []);
        $stock = (array) ($record['stock'] ?? []);
        $images = array_values(array_filter((array) ($record['images'] ?? []), 'is_array'));

        return [
            'external_id' => (string) $record['external_id'],
            'type' => self::limit((string) ($record['type'] ?? 'simple'), 20),
            'status' => self::limit((string) ($record['status'] ?? 'publish'), 20),
            'title' => self::limit((string) ($record['title'] ?? ''), 500),
            'url' => self::nullableString($record['url'] ?? null),
            'sku' => self::nullableString($record['sku'] ?? null, 120),
            'brand' => self::brand($record),
            'price' => self::money($price['price'] ?? null) ?? self::money($price['min_price'] ?? null),
            'regular_price' => self::money($price['regular_price'] ?? null),
            'currency' => self::nullableString($price['currency'] ?? null, 3),
            'on_sale' => (bool) ($price['on_sale'] ?? false),
            'in_stock' => (bool) ($stock['in_stock'] ?? false),
            'purchasable' => (bool) ($record['purchasable'] ?? false),
            'image_url' => self::nullableString($images[0]['url'] ?? null),
            'variations_count' => count((array) ($record['variations'] ?? [])),
            'source_updated_at' => self::date($record['updated_at'] ?? null),
            // how many it sold changes with every order and is no change to the product: never in its hash
            'hash' => self::hash(array_diff_key($payload, ['sales' => true])),
            'payload' => $payload,
        ];
    }

    /**
     * The record as stored: no sensitive fields, and lists whose order carries no meaning put in
     * a fixed order. Some stores return categories and tags in a different order on every
     * request; without this, an unchanged product would look changed on every sync.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function canonicalProduct(array $record): array
    {
        unset($record['hash']);

        $meta = SensitiveFields::strip((array) ($record['meta'] ?? []));
        ksort($meta, SORT_STRING);
        $record['meta'] = $meta;

        $categories = array_values(array_filter((array) ($record['categories'] ?? []), 'is_array'));
        usort($categories, fn (array $a, array $b): int => strnatcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));
        $record['categories'] = $categories;

        foreach (['tags', 'brands'] as $list) {
            $values = array_values(array_map(fn ($v): string => is_array($v) ? (string) ($v['name'] ?? '') : (string) $v, (array) ($record[$list] ?? [])));
            sort($values, SORT_STRING);
            $record[$list] = $values;
        }

        $relations = array_values(array_filter((array) ($record['relations'] ?? []), 'is_array'));
        usort($relations, fn (array $a, array $b): int => [(string) ($a['type'] ?? ''), (int) ($a['target'] ?? 0)] <=> [(string) ($b['type'] ?? ''), (int) ($b['target'] ?? 0)]);
        $record['relations'] = $relations;

        return $record;
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    public static function productCategoryIds(array $record): array
    {
        return array_values(array_unique(array_map(
            fn (array $category): string => (string) $category['id'],
            array_filter((array) ($record['categories'] ?? []), fn ($c): bool => is_array($c) && isset($c['id'])),
        )));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function content(array $record): array
    {
        $terms = [];
        foreach ((array) ($record['terms'] ?? []) as $taxonomy => $names) {
            $names = array_values(array_map('strval', (array) $names));
            sort($names, SORT_STRING);
            $terms[(string) $taxonomy] = $names;
        }
        ksort($terms, SORT_STRING);
        $record['terms'] = $terms;
        unset($record['hash']);

        return [
            'type' => self::limit((string) ($record['type'] ?? 'post'), 40),
            'external_id' => (string) $record['external_id'],
            'title' => self::limit((string) ($record['title'] ?? ''), 500),
            'url' => self::nullableString($record['url'] ?? null),
            'image_url' => self::nullableString($record['image'] ?? null),
            'excerpt' => self::nullableString($record['excerpt'] ?? null),
            'body' => self::nullableString($record['text'] ?? null),
            'terms' => $terms,
            'product_external_ids' => array_values(array_map('strval', (array) ($record['product_ids'] ?? []))),
            'source_updated_at' => self::date($record['updated_at'] ?? null),
            'hash' => self::hash($record),
        ];
    }

    /** @param array<string, mixed> $record */
    private static function brand(array $record): ?string
    {
        foreach ((array) ($record['brands'] ?? []) as $brand) {
            $name = is_array($brand) ? ($brand['name'] ?? null) : $brand;
            if (is_string($name) && trim($name) !== '') {
                return self::limit(trim($name), 120);
            }
        }

        foreach ((array) ($record['attributes'] ?? []) as $attribute) {
            if (is_array($attribute) && ($attribute['key'] ?? null) === 'pa_brand') {
                $value = ((array) ($attribute['values'] ?? []))[0] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return self::limit(trim($value), 120);
                }
            }
        }

        return null;
    }

    private static function money(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function nullableString(mixed $value, int $max = 0): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $max > 0 ? self::limit($value, $max) : $value;
    }

    private static function limit(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }
}
