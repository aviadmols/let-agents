<?php

namespace App\Modules\Search\Support;

use App\Core\Facades\Settings;

/**
 * The words a store's products are described with: its filters' values, then the words of their titles and words (tags,
 * attributes, the picture words its scan wrote: "כיס חזה", "פסים"), those some say and not most, the most said first,
 * as Hebrew writes them. Read from the search index, so new products' words join with the next build. With a hash of the
 * list: what was read against it stays true until it changes (WordMeanings).
 */
final class StoreWords
{
    /** A word counts as the store's when at least this many products say it, and at most this share of them. */
    private const MIN_PRODUCTS = 2;

    private const MAX_SHARE = 0.5;

    /** @var array<string, array{0: list<string>, 1: string}> index hash => the words and their hash */
    private static array $read = [];

    /**
     * @param  array<string, mixed>  $index  the loaded index (records, facets, hash)
     * @return array{0: list<string>, 1: string}
     */
    public static function of(string $shopId, array $index): array
    {
        $key = $shopId.':'.(string) ($index['hash'] ?? '');
        if (! isset(self::$read[$key])) {
            $words = self::vocabulary(array_values((array) ($index['records'] ?? [])), (array) ($index['facets'] ?? []), (int) Settings::get('search.vocabulary_terms', $shopId));
            self::$read = [$key => [$words, substr(hash('sha256', implode('|', $words)), 0, 20)]];
        }

        return self::$read[$key];
    }

    /** Rare forms read beside a store's words, at most. */
    private const RARE_MAX = 200;

    /**
     * The words one product alone says that share a root with the store's words (rootKeys): "נמר" on Vans' one leopard
     * sneaker beside the "מנומר" of three. Too rare to be the store's words, a form of one all the same: its families
     * read them (WriteWordFamilies), so a search for either finds both.
     *
     * @param  array<string, mixed>  $index  the loaded index
     * @param  list<string>  $words  the store's words (of)
     * @return list<string>
     */
    public static function rareForms(array $index, array $words): array
    {
        $roots = [];
        foreach ($words as $word) {
            foreach (self::rootKeys(HebrewSearch::normalize($word)) as $key) {
                $roots[$key] = true;
            }
        }
        $known = array_flip(array_map(fn (string $w): string => HebrewSearch::normalize($w), $words));
        $said = [];
        foreach ((array) ($index['records'] ?? []) as $item) {
            if (! is_array($item) || ($item['t'] ?? null) !== 'product') {
                continue;
            }
            foreach (array_unique(explode(' ', HebrewSearch::normalize((string) ($item['title'] ?? '').' '.(string) ($item['kw'] ?? '')))) as $word) {
                $said[$word] = ($said[$word] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($said as $word => $n) {
            $word = (string) $word;
            if ($n === 1 && ! isset($known[$word]) && preg_match('/^[א-ת]{3,}$/u', $word) === 1 && ! HebrewSearch::isFiller($word)
                && array_filter(self::rootKeys($word), fn (string $key): bool => isset($roots[$key])) !== []) {
                $out[] = HebrewSearch::withFinals($word);
            }
        }
        sort($out, SORT_STRING);

        return array_slice($out, 0, self::RARE_MAX);
    }

    /**
     * A single Hebrew word's root as letters (HebrewSearch::stem, then its consonants), and without a letter a form of a
     * root begins with ("מנומר": מנמר and נמר; "הדפס": הדפס and דפס). Forms of one word share one; some words only look alike.
     *
     * @return list<string>
     */
    public static function rootKeys(string $plain): array
    {
        if (str_contains($plain, ' ') || preg_match('/^[א-ת]{3,}$/u', $plain) !== 1) {
            return [];
        }
        $root = HebrewSearch::skeleton(HebrewSearch::stem($plain));

        return preg_match('/^[מתה](.{3,})$/u', $root, $m) === 1 ? [$root, $m[1]] : [$root];
    }

    /**
     * @param  list<array<string, mixed>>  $items  index records (t, title, kw)
     * @param  list<array<string, mixed>>  $facets
     * @return list<string>
     */
    public static function vocabulary(array $items, array $facets, int $max): array
    {
        if ($max < 1) {
            return [];
        }
        $terms = [];
        foreach ($facets as $facet) {
            foreach ((array) ($facet['v'] ?? []) as $value) {
                $label = trim((string) ($value['l'] ?? ''));
                if (mb_strlen($label) >= 2 && preg_match('/\p{L}/u', $label) === 1) {
                    $terms[$label] = PHP_INT_MAX;
                }
            }
        }
        $said = [];
        $tagged = [];
        $products = 0;
        foreach ($items as $item) {
            if (! is_array($item) || ($item['t'] ?? null) !== 'product') {
                continue;
            }
            $products++;
            // its tags too, each word and each tag whole: a store's topics live there ("חטופים ושבי" on Sela's books)
            // a tag is [its words, how central] (BuildSearchIndex), read for its words
            $tags = array_values(array_filter(array_map(fn ($tag): string => (string) (is_array($tag) ? ($tag[0] ?? '') : $tag), is_array($item['tg'] ?? null) ? $item['tg'] : [(string) ($item['tg'] ?? '')])));
            foreach (array_unique(explode(' ', HebrewSearch::normalize((string) ($item['title'] ?? '').' '.(string) ($item['kw'] ?? '').' '.implode(' ', $tags)))) as $word) {
                if (mb_strlen($word) >= 3 && preg_match('/^\p{L}+$/u', $word) === 1 && ! HebrewSearch::isFiller($word)) {
                    $said[$word] = ($said[$word] ?? 0) + 1;
                }
            }
            foreach (array_unique(array_map('trim', $tags)) as $tag) {
                if (str_contains($tag, ' ') && mb_strlen($tag) <= 40) {
                    $tagged[$tag] = ($tagged[$tag] ?? 0) + 1;
                }
            }
        }
        foreach ($said as $word => $n) {
            if ($n >= self::MIN_PRODUCTS && $n <= max(self::MIN_PRODUCTS, $products * self::MAX_SHARE)) {
                $terms[HebrewSearch::withFinals((string) $word)] ??= $n;
            }
        }
        foreach ($tagged as $tag => $n) {
            if ($n >= self::MIN_PRODUCTS) {
                $terms[$tag] ??= $n;
            }
        }
        arsort($terms);

        return array_slice(array_map('strval', array_keys($terms)), 0, $max);
    }
}
