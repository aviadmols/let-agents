<?php

namespace App\Modules\Assistant\Support;

/**
 * The sentences a store repeats on most of its product pages: "החזר כספי מלא תוך 14 יום",
 * "אפשרות לשריין מוצר באמצעות פריסת תשלומים". The theme prints them beside every product, so they are
 * about the store, not the product, and no product description holds them. Read by code only.
 */
final class RepeatedText
{
    private const MIN_CHARS = 8;

    private const MAX_CHARS = 300;

    /** The page's sentences, once each: the main part when the page marks one, without menus or scripts. */
    public static function sentences(string $html): array
    {
        $html = (string) preg_replace('#<(script|style|noscript|svg|template|select|video|audio|iframe|object|nav|header|footer)\b[^>]*>.*?</\1\s*>#isu', ' ', $html);

        if (preg_match('#<main\b[^>]*>(.*)</main>#isu', $html, $main) === 1) {
            $html = $main[1];
        }

        // Every block ends a line; inline tags join their text.
        $html = (string) preg_replace('#</?(br|p|div|li|ul|ol|h[1-6]|section|article|aside|tr|td|th|dd|dt|dl|summary|details|figcaption|blockquote|label|table)\b[^>]*>#iu', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sentences = [];

        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}]+/u', ' ', $line));

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                $sentence = trim((string) $sentence);

                if (self::worth($sentence)) {
                    $sentences[self::key($sentence)] = $sentence;
                }
            }
        }

        return $sentences;
    }

    /**
     * The sentences found on at least $share of the pages, and on three at the least, the most
     * repeated first.
     *
     * @param  list<array<string, string>>  $pages  each page's sentences, as sentences() gives them
     * @return list<array{text: string, pages: int}>
     */
    public static function repeated(array $pages, float $share, int $limit = 40): array
    {
        $count = [];
        $text = [];
        $first = [];

        foreach ($pages as $sentences) {
            foreach ($sentences as $key => $sentence) {
                $count[$key] = ($count[$key] ?? 0) + 1;
                $text[$key] ??= $sentence;
                $first[$key] ??= count($first);
            }
        }

        // "עבור לפריט 1", "עבור לפריט 2": a gallery's labels, the same words with a running number.
        $numbered = [];
        foreach (array_keys($count) as $key) {
            $numbered[(string) preg_replace('/\d+/u', '#', $key)][] = $key;
        }
        $labels = array_merge([], ...array_values(array_filter($numbered, fn (array $keys): bool => count($keys) >= 3)));

        $needed = max(3, (int) ceil(count($pages) * $share));
        $kept = array_values(array_diff(array_keys(array_filter($count, fn (int $n): bool => $n >= $needed)), $labels));
        // The most repeated first; among equals, in the order the page shows them.
        usort($kept, fn (string $a, string $b): int => [$count[$b], $first[$a]] <=> [$count[$a], $first[$b]]);

        return array_map(fn (string $key): array => ['text' => $text[$key], 'pages' => $count[$key]], array_slice($kept, 0, $limit));
    }

    public static function key(string $sentence): string
    {
        return mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $sentence)));
    }

    /** A sentence a shopper would read: some words, mostly letters, not a lone number or a label. */
    private static function worth(string $sentence): bool
    {
        $length = mb_strlen($sentence);

        return $length >= self::MIN_CHARS && $length <= self::MAX_CHARS
            && preg_match_all('/\p{L}/u', $sentence) >= 6
            && count(preg_split('/\s+/u', $sentence) ?: []) >= 3;
    }
}
