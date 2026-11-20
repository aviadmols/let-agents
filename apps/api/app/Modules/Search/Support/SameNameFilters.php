<?php

namespace App\Modules\Search\Support;

/**
 * One filter under one name. A store's index is built from several readers, and two of them may make a filter of the same
 * name: the sizes its variants come in and the sizes its vocabulary reads from the products' words, both "מידה"; its brands
 * and the vocabulary's "מותג" (Shilav, 20.11: "מידה" twice on the filter sheet, one of them holding "7Y" alone). The
 * shopper sees one: the filter with the most values stays, every value of the other is found in it by its label ("0-3m" is
 * "0-3m", "ביבס" is "ביבס Bibs") or added to it, each product of the value holds it there too, and the other filter
 * leaves the screen (`hide: 1`). No code moves, so every product's `f` stays readable; nothing a filter reached is lost.
 *
 * Code only, every store, whatever the filters are.
 */
final class SameNameFilters
{
    /** A filter's values are coded 0..999 inside it (filter * 1000 + value). */
    private const MOST_VALUES = 1000;

    /**
     * @param  list<array<string, mixed>>  $facets
     * @param  list<array<string, mixed>>  $items  the index's records (each product's `f` codes)
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} the facets and the records
     */
    public static function merged(array $facets, array $items): array
    {
        $named = [];
        foreach ($facets as $g => $facet) {
            $name = self::name((string) ($facet['l'] ?? ''));
            if ($name !== '' && ! isset($facet['hide']) && is_array($facet['v'] ?? null)) {
                $named[$name][] = $g;
            }
        }

        // a product's code in the filter that leaves => its code in the filter that stays
        $also = [];
        foreach ($named as $filters) {
            if (count($filters) < 2) {
                continue;
            }
            $keep = $filters[0];
            foreach ($filters as $g) {
                $keep = count($facets[$g]['v']) > count($facets[$keep]['v']) ? $g : $keep;
            }
            foreach ($filters as $g) {
                if ($g === $keep) {
                    continue;
                }
                $moved = [];
                $values = $facets[$keep]['v'];
                foreach ($facets[$g]['v'] as $i => $value) {
                    $at = self::valueAt($values, (string) ($value['l'] ?? ''));
                    if ($at === null) {
                        if (count($values) >= self::MOST_VALUES) {
                            // no room for it: the filter stays as it was, on the screen
                            continue 2;
                        }
                        $at = count($values);
                        $values[] = $value;
                    }
                    $moved[$g * 1000 + $i] = $keep * 1000 + $at;
                }
                // the order its values are shown in (`vo`), the added ones last
                if (isset($facets[$keep]['vo']) && is_array($facets[$keep]['vo']) && count($values) > count($facets[$keep]['v'])) {
                    $facets[$keep]['vo'] = [...$facets[$keep]['vo'], ...range(count($facets[$keep]['v']), count($values) - 1)];
                }
                $facets[$keep]['v'] = $values;
                $facets[$g]['hide'] = 1;
                $also += $moved;
            }
        }

        // ...and one value under one name inside a filter: "מבריק" twice in Gueta's finish (gloss and glossy). The first
        // stays; each other one leaves the screen (`vh`), its products holding the first too
        foreach ($facets as $g => $facet) {
            if (isset($facet['hide']) || ! is_array($facet['v'] ?? null)) {
                continue;
            }
            $first = [];
            foreach ($facet['v'] as $i => $value) {
                $name = self::name((string) ($value['l'] ?? ''));
                if ($name === '' || in_array($i, (array) ($facet['vh'] ?? []), true)) {
                    continue;
                }
                if (! isset($first[$name])) {
                    $first[$name] = $i;

                    continue;
                }
                $facets[$g]['vh'] = array_values(array_unique([...(array) ($facets[$g]['vh'] ?? []), $i]));
                $also[$g * 1000 + $i] = $g * 1000 + $first[$name];
            }
        }

        if ($also === []) {
            return [$facets, $items];
        }
        foreach ($items as $n => $item) {
            $codes = (array) ($item['f'] ?? []);
            $add = [];
            foreach ($codes as $code) {
                if (isset($also[(int) $code])) {
                    $add[] = $also[(int) $code];
                }
            }
            if ($add !== []) {
                $items[$n]['f'] = array_values(array_unique([...array_map('intval', $codes), ...$add]));
            }
        }

        return [$facets, $items];
    }

    /**
     * Where a value of this label is among the values: the same label, else the one value whose words hold all of its
     * words or whose words it holds all of ("ביבס" in "ביבס Bibs"). Null when none, or more than one, is.
     *
     * @param  list<array<string, mixed>>  $values
     */
    private static function valueAt(array $values, string $label): ?int
    {
        $name = self::name($label);
        if ($name === '') {
            return null;
        }
        $words = self::sounds($name);
        $near = [];
        foreach ($values as $i => $value) {
            $other = self::name((string) ($value['l'] ?? ''));
            if ($other === $name) {
                return $i;
            }
            $theirs = $other === '' ? [] : self::sounds($other);
            if ($theirs !== [] && (array_diff($words, $theirs) === [] || array_diff($theirs, $words) === [])) {
                $near[] = $i;
            }
        }

        return count($near) === 1 ? $near[0] : null;
    }

    /**
     * Each word of a label as it sounds in Hebrew letters: a Latin word as Hebrew writes it (Transliterate), the letters
     * Hebrew may or may not write for a vowel (א, ה, ו, י) left out, so "סטנלי" is "Stanley" and "מקיטה" "Makita" (Gueta:
     * the vocabulary's brands in Hebrew beside the store's in English). A word with no Latin letters is as written.
     *
     * @return list<string>
     */
    private static function sounds(string $name): array
    {
        $out = [];
        foreach (explode(' ', $name) as $word) {
            $spelled = preg_match('/[a-z]/', $word) === 1 ? (Transliterate::hebrew($word)[1] ?? Transliterate::hebrew($word)[0] ?? $word) : $word;
            $bare = (string) preg_replace('/(?<=\p{Hebrew})[אהוי]/u', '', $spelled);
            $out[] = $bare === '' ? $word : $bare;
        }

        return $out;
    }

    private static function name(string $label): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($label)));
    }
}
