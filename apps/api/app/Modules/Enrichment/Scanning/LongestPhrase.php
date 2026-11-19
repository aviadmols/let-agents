<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * The values of one attribute read in one text, the longest phrase first: a span of the text a longer phrase of one value
 * has taken is not read again by a shorter word of another value. "Sterling silver" is a metal of its own, and inside
 * "14K gold over sterling silver" it is the gold-over-silver value's, not a second metal: the plain value and the longer
 * one stand side by side in one attribute, and a product says only the one its words say.
 *
 * A word of the same value inside its own longer phrase is no conflict, and two phrases of one length both stand: only a
 * strictly longer phrase takes a span from another value. Every reading of an attribute that asks for it goes through
 * here (resolve), so the expert bank's measurement (BankEvidence) and the code reading of its vocabulary (CodeReading)
 * read a product alike.
 */
final class LongestPhrase
{
    /**
     * The first span each value's patterns read that no longer phrase of another value took.
     *
     * @param  array<string, list<string>>  $patterns  each value's patterns, by value key, in the attribute's order
     * @return array<string, array{0: int, 1: int}> by value key, in the attribute's order: [offset, length] in bytes
     */
    public static function claims(string $text, array $patterns): array
    {
        if ($text === '') {
            return [];
        }

        $spans = [];
        foreach ($patterns as $value => $list) {
            foreach (array_values($list) as $index => $pattern) {
                foreach (PatternMatch::all($text, (string) $pattern) as [$offset, $length]) {
                    $spans[] = ['value' => (string) $value, 'word' => $index, 'offset' => $offset, 'length' => $length];
                }
            }
        }

        $first = [];
        foreach (self::resolve($spans, array_map('strval', array_keys($patterns))) as $span) {
            if (! isset($first[$span['value']]) || $span['offset'] < $first[$span['value']][0]) {
                $first[$span['value']] = [$span['offset'], $span['length']];
            }
        }

        $out = [];
        foreach (array_keys($patterns) as $value) {
            if (isset($first[(string) $value])) {
                $out[(string) $value] = $first[(string) $value];
            }
        }

        return $out;
    }

    /**
     * The spans that stand: the longest first (then the earliest, then the value first in the attribute), and a span
     * overlapping one a strictly longer phrase of another value already took is left out.
     *
     * @template T of array{value: string, offset: int, length: int}
     *
     * @param  list<T>  $spans  every whole-word match of the attribute's words in one text
     * @param  list<string>  $order  the attribute's value keys, in its order
     * @return list<T>
     */
    public static function resolve(array $spans, array $order): array
    {
        $rank = array_flip($order);
        usort($spans, fn (array $a, array $b): int => [$b['length'], $a['offset'], $rank[$a['value']] ?? PHP_INT_MAX]
            <=> [$a['length'], $b['offset'], $rank[$b['value']] ?? PHP_INT_MAX]);

        $taken = [];
        foreach ($spans as $span) {
            $end = $span['offset'] + $span['length'];
            foreach ($taken as $other) {
                if ($other['value'] !== $span['value'] && $other['length'] > $span['length']
                    && $span['offset'] < $other['offset'] + $other['length'] && $other['offset'] < $end) {
                    continue 2;
                }
            }
            $taken[] = $span;
        }

        return $taken;
    }
}
