<?php

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Models\CatalogProduct;

/**
 * The lines of an order as an AI writes them, in one place for the order link, the cart page, the
 * cart tools and the order inside the store: "31313:1,7677/42928:2,14283@5.4:4" (a product, its
 * option, its length in metres, how many), or a product's name where an AI lost its id
 * ("מטאטא דשא:2"). Code only.
 */
final class OrderItems
{
    /** At most this many lines: a project (a pergola: posts, beams, rafters, brackets, screws). */
    public const MAX_LINES = 30;

    /** At most this many of one line: thirty boards of decking is an order. */
    public const MAX_QUANTITY = 99;

    /** How alike (percent) a name and a title must be to be the same product. */
    private const SAME_NAME = 85.0;

    /**
     * @return list<array{product: string, variant: string, quantity: int, length?: float, name?: string}>
     */
    public static function parse(string $text): array
    {
        $items = [];

        foreach (array_slice(array_filter(array_map('trim', explode(',', $text))), 0, self::MAX_LINES) as $part) {
            if (preg_match('~^([A-Za-z0-9_.-]{1,64})(?:/([A-Za-z0-9_.-]{1,64}))?(?:@(\d{1,3}(?:[.,]\d{1,3})?)\s*(?:m|מ|מטר)?)?(?::(\d{1,3}))?$~u', $part, $m) === 1) {
                $items[] = array_filter([
                    'product' => $m[1],
                    'variant' => $m[2] ?? '',
                    'quantity' => self::quantity(($m[4] ?? '') !== '' ? $m[4] : 1),
                    'length' => ($m[3] ?? '') !== '' ? (float) str_replace(',', '.', $m[3]) : null,
                ], fn ($v): bool => $v !== null);

                continue;
            }
            if (preg_match('~^(.{2,160}?)(?:\s*[:*]\s*(\d{1,3}))?$~u', $part, $m) === 1) {
                $items[] = ['product' => '', 'variant' => '', 'quantity' => self::quantity($m[2] ?? 1), 'name' => trim($m[1])];
            }
        }

        return $items;
    }

    /**
     * The lines a tool sent as objects ({product, variant?, length?, quantity?}), the same shape.
     *
     * @param  list<mixed>  $lines
     * @return list<array{product: string, variant: string, quantity: int, length?: float}>
     */
    public static function fromTool(array $lines): array
    {
        $items = [];

        foreach (array_slice(array_values(array_filter($lines, 'is_array')), 0, self::MAX_LINES) as $line) {
            $items[] = array_filter([
                'product' => trim((string) ($line['product'] ?? $line['id'] ?? '')),
                'variant' => trim((string) ($line['variant'] ?? $line['option'] ?? '')),
                'quantity' => self::quantity($line['quantity'] ?? 1),
                'length' => is_numeric($line['length'] ?? null) ? (float) $line['length'] : null,
            ], fn ($v): bool => $v !== null);
        }

        return $items;
    }

    /**
     * Each named item as the store's product of that name: the same words in any order, without
     * quotes or punctuation, or else the closest title when it is close enough to be the same
     * product. A name like no product stays as it is, and the order says the store has no such
     * product. Inside the shop's tenant.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function named(array $items): array
    {
        if (! in_array(true, array_map(fn (array $item): bool => isset($item['name']), $items), true)) {
            return $items;
        }

        $plain = function (string $text): string {
            $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}.\/]+/u', ' ', mb_strtolower(str_replace('+', ' ', $text)))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            sort($words, SORT_STRING);

            return implode(' ', $words);
        };
        $titles = CatalogProduct::query()->whereNull('removed_at')->orderBy('id')->pluck('title', 'external_id')
            ->map(fn ($title): string => $plain((string) $title))->all();

        return array_map(function (array $item) use ($plain, $titles): array {
            if (! isset($item['name'])) {
                return $item;
            }
            $wanted = $plain((string) $item['name']);
            $found = array_search($wanted, $titles, true);

            if ($found === false) {
                $best = 0.0;
                foreach ($titles as $id => $title) {
                    similar_text($wanted, $title, $percent);
                    if ($percent > $best) {
                        [$best, $found] = [$percent, $id];
                    }
                }
                $found = $best >= self::SAME_NAME ? $found : false;
            }

            return ['product' => $found === false ? mb_substr((string) $item['name'], 0, 64) : (string) $found, 'variant' => '', 'quantity' => $item['quantity']];
        }, $items);
    }

    private static function quantity(mixed $value): int
    {
        return max(1, min(self::MAX_QUANTITY, (int) $value));
    }
}
