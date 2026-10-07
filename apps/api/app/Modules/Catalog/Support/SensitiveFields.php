<?php

namespace App\Modules\Catalog\Support;

/**
 * Custom fields Let Agents never stores: costs, supplier details, margins and internal notes.
 *
 * The store plugin already leaves these out (from version 0.1.1). This is the second lock, for
 * older plugins and for platforms that do not filter at the source.
 */
final class SensitiveFields
{
    private const PATTERNS = [
        'cost', 'supplier', 'vendor', 'wholesale', 'margin', 'profit', 'purchase', 'buy_price',
        'internal', 'private', 'secret', 'note', 'comment',
        'עלות', 'ספק', 'סיטונא', 'רווח', 'קנייה', 'קניה', 'פנימי', 'הערה', 'הערת', 'לא לפרסום', 'חסוי',
    ];

    public static function isSensitive(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function strip(array $meta): array
    {
        return array_filter($meta, fn ($value, $key): bool => ! self::isSensitive((string) $key), ARRAY_FILTER_USE_BOTH);
    }
}
