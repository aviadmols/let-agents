<?php

namespace App\Modules\Search\Support;

/**
 * A print a search or a product's page asks for ("נעלי משבצות"), read against how much of each product its picture
 * shows covered in it (caption v8: a name and how much it covers). What is covered in the print asked for comes
 * first, what has it on one part next, what has it on a logo or a label only last; a product whose picture was
 * described before the pictures said this stays where the search put it, between the two. Code only.
 */
final class Patterns
{
    /**
     * Each print as a shopper, a title or a picture's words name it, Hebrew and English: whole normalized words
     * (final letters plain), up to two prefix letters allowed before. One family a print; the last is a print of any kind.
     */
    private const FAMILIES = [
        ['משבצות', 'משובצ', 'משובצת', 'משובצות', 'משובצימ', 'שחמט', 'check', 'checks', 'checked', 'checkered', 'chequered', 'checkerboard', 'plaid', 'tartan', 'gingham'],
        ['פסימ', 'מפוספס', 'מפוספסת', 'מפוספסימ', 'מפוספסות', 'stripe', 'stripes', 'striped', 'pinstripe'],
        ['פרחוני', 'פרחונית', 'פרחוניימ', 'פרחוניות', 'פרחימ', 'floral', 'flower', 'flowers', 'flowered'],
        ['נקודות', 'מנוקד', 'מנוקדת', 'polka', 'dots', 'dotted'],
        ['נמר', 'מנומר', 'מנומרת', 'leopard', 'cheetah'],
        ['זברה', 'zebra'],
        ['הסוואה', 'camo', 'camouflage'],
        ['פייזלי', 'paisley'],
        ['הדפס', 'הדפסימ', 'מודפס', 'מודפסת', 'print', 'printed', 'graphic'],
    ];

    /** The family that names a print of any kind ("חולצה עם הדפס"): every print counts for it. */
    private const ANY = 8;

    /** How much of the product its picture shows covered, by what the describer said: a rank, the more the higher. */
    private const LEVELS = ['all' => 4, 'most' => 3, 'part' => 2, 'detail' => 1, 'none' => 0];

    /** A picture described before it said how much a print covers: neither first nor last. */
    private const UNKNOWN = 1.5;

    /** The print a search names, as its family; null when it names none. */
    public static function asked(string $query): ?int
    {
        foreach (explode(' ', HebrewSearch::normalize($query)) as $word) {
            $family = self::family($word);
            if ($family !== null) {
                return $family;
            }
        }

        return null;
    }

    /** The family of a print named by a word or a short name ("משבצות", "לוגו משבצות"); null when none. */
    public static function family(string $name): ?int
    {
        foreach (explode(' ', HebrewSearch::normalize($name)) as $word) {
            foreach (self::FAMILIES as $at => $forms) {
                if (preg_match('/^[והבלמשכ]{0,2}(?:'.implode('|', array_map(fn (string $f): string => preg_quote($f, '/'), $forms)).')$/u', $word) === 1) {
                    return $at;
                }
            }
        }

        return null;
    }

    /**
     * What a product's picture says of its print, as the index keeps it (BuildSearchIndex, 'pt'): its family and how
     * much it covers; family null when the picture says it is plain or names a print no family knows; null when the
     * picture has not said.
     *
     * @param  array<string, mixed>  $record
     * @return array{family: int|null, level: int}|null
     */
    public static function of(array $record): ?array
    {
        $said = $record['pt'] ?? null;
        if (! is_array($said) || ! isset(self::LEVELS[(string) ($said['c'] ?? '')])) {
            return null;
        }
        $family = isset($said['n']) ? self::family((string) $said['n']) : null;

        return ['family' => $family, 'level' => $family === null ? 0 : self::LEVELS[(string) $said['c']]];
    }

    /**
     * The records in tiers by how much of each the print asked for covers, the order given kept within each: all
     * of it, most, a part, then those whose picture has not said, then a detail only, then the plain and the
     * ones in another print. Unchanged when no print is asked for.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public static function tiered(array $records, ?int $family): array
    {
        if ($family === null || count($records) < 2) {
            return $records;
        }
        $tier = [];
        foreach ($records as $at => $record) {
            $said = self::of($record);
            $tier[$at] = $said === null ? self::UNKNOWN : ($said['family'] !== null && ($family === self::ANY || $said['family'] === $family) ? (float) $said['level'] : 0.0);
        }
        $order = array_keys($records);
        usort($order, fn (int $a, int $b): int => [$tier[$b], $a] <=> [$tier[$a], $b]);

        return array_map(fn (int $at): array => $records[$at], $order);
    }

    /** Whether a product's picture shows it covered in a print of a family (most of it or all), for its alike to be tiered by. */
    public static function covered(array $record): ?int
    {
        $said = self::of($record);

        return $said !== null && $said['family'] !== null && $said['level'] >= self::LEVELS['part'] ? $said['family'] : null;
    }
}
