<?php

namespace App\Modules\Taxonomy\Support;

use App\Modules\Taxonomy\Models\TaxonomyGroup;
use App\Modules\Taxonomy\Models\TaxonomyTag;

/**
 * The exact value behind a tag that is a range ("9–11 days", "12+ days", "0.25 to 0.5 ct", "Up to 0.25 ct"): the
 * number the store's words give, read from the passage the tag rests on, shown and searched beside the range ("10
 * days"). Code only, the same for every store: the range is read from the tag's own name, and a number counts only
 * when it falls in it and stands by the range's unit ("ten-night trip" is not ten days), or is the passage's only
 * number. Two different numbers that both fit say nothing sure: none. A price is never one (it is not tagged).
 */
final class Exact
{
    private const NUM = '\d+(?:[.,]\d+)?';

    /** Other ways a unit is written, both languages, so "10-Day", "עשרה ימים" and "0.7 קארט" all count. */
    private const UNITS = [
        ['day', 'days', 'יום', 'ימים', 'd'],
        ['ct', 'carat', 'carats', 'קארט', 'קראט'],
        ['year', 'years', 'שנה', 'שנים', 'yr', 'yrs'],
        ['month', 'months', 'חודש', 'חודשים'],
        ['hour', 'hours', 'שעה', 'שעות', 'h'],
        ['cm', 'centimeter', 'centimeters', 'ס"מ', 'סמ'],
        ['mm', 'millimeter', 'millimeters', 'מ"מ', 'ממ'],
        ['kg', 'kilo', 'kilos', 'ק"ג', 'קילו'],
        ['g', 'gram', 'grams', 'גרם'],
        ['ml', 'מ"ל'],
        ['page', 'pages', 'עמוד', 'עמודים'],
    ];

    private const WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
        'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30,
        'אחד' => 1, 'אחת' => 1, 'שניים' => 2, 'שני' => 2, 'שתיים' => 2, 'שתי' => 2, 'שלושה' => 3, 'שלוש' => 3, 'ארבעה' => 4, 'ארבע' => 4,
        'חמישה' => 5, 'חמש' => 5, 'שישה' => 6, 'שש' => 6, 'שבעה' => 7, 'שבע' => 7, 'שמונה' => 8, 'תשעה' => 9, 'תשע' => 9, 'עשרה' => 10, 'עשר' => 10,
    ];

    /**
     * The range a tag names, with its unit in both languages, or null when its name is not a range of a group of
     * facts (a karat, "14K gold", is one value already; a theme is no number).
     *
     * @return array{min: float|null, max: float|null, unit_en: string, unit_he: string}|null
     */
    public static function range(TaxonomyTag $tag): ?array
    {
        $group = $tag->group;
        if ($group === null || $group->kind !== TaxonomyGroup::FACTUAL || preg_match('/price|מחיר/iu', $group->key.' '.$group->label_en.' '.$group->label_he)) {
            return null;
        }

        $en = trim((string) $tag->label_en);
        $n = self::NUM;
        $range = match (true) {
            (bool) preg_match("/^($n)\s*(?:–|—|-|to)\s*($n)\s*(.*)$/iu", $en, $m) => [self::num($m[1]), self::num($m[2]), $m[3]],
            (bool) preg_match("/^($n)\s*\+\s*(.*)$/iu", $en, $m) => [self::num($m[1]), null, $m[2]],
            (bool) preg_match("/^(?:over|above|more than)\s+($n)\s*(.*)$/iu", $en, $m) => [self::num($m[1]) + 1e-9, null, $m[2]],
            (bool) preg_match("/^(?:up to|under|less than|below)\s+($n)\s*(.*)$/iu", $en, $m) => [null, self::num($m[1]), $m[2]],
            default => null,
        };
        if ($range === null || ($range[0] !== null && $range[1] !== null && $range[0] >= $range[1])) {
            return null;
        }

        // the Hebrew name less its numbers and range words is its unit ("1–3 ימים", "12 ימים ומעלה", "עד 0.25 קארט")
        $he = trim((string) preg_replace(["/$n/u", '/(?<!\S)(?:עד|מעל|ומעלה|מעלה|ומטה|מתחת ל-?|בין|ל-|ו-)(?!\S)/u', '/[–—+\-]/u', '/\s+/u'], ['', '', '', ' '], (string) $tag->label_he));

        return ['min' => $range[0], 'max' => $range[1], 'unit_en' => trim($range[2]), 'unit_he' => $he !== '' ? $he : trim($range[2])];
    }

    /** The number the passage gives that falls in the tag's range, or null when there is none, or more than one. */
    public static function value(TaxonomyTag $tag, ?string $evidence): ?float
    {
        $range = self::range($tag);
        $text = mb_strtolower(trim((string) $evidence));
        if ($range === null || $text === '') {
            return null;
        }

        $units = self::units($range);
        $words = implode('|', array_map(fn (string $w): string => preg_quote($w, '/'), array_keys(self::WORDS)));
        preg_match_all('/(?<![\p{L}\d.,])('.self::NUM.'|'.$words.')(?![\p{L}\d])\s*-?\s*([\p{L}"\']*)/u', $text, $found, PREG_SET_ORDER);

        $bare = [];
        $unitBeside = [];
        foreach ($found as [, $number, $after]) {
            $value = is_numeric(str_replace(',', '.', $number)) ? self::num($number) : (float) self::WORDS[$number];
            if (($range['min'] !== null && $value < $range['min']) || ($range['max'] !== null && $value > $range['max'])) {
                continue;
            }
            if (in_array($after, $units, true)) {
                $unitBeside[(string) $value] = $value;
            } elseif ($after === '') {
                $bare[(string) $value] = $value;
            }
        }

        // by the unit when the passage says it; else the passage's one number standing alone (never "ten-night")
        $sure = $unitBeside !== [] ? $unitBeside : (count($found) === 1 ? $bare : []);

        return count($sure) === 1 ? array_values($sure)[0] : null;
    }

    /** The exact value as a tag's name reads: "10 days", "10 ימים", "0.7 קארט". */
    public static function label(TaxonomyTag $tag, float $value, string $locale): ?string
    {
        $range = self::range($tag);
        if ($range === null) {
            return null;
        }
        $number = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        $unit = $locale === 'he' ? $range['unit_he'] : $range['unit_en'];
        if ($value == 1.0 && $locale !== 'he') {
            $unit = preg_replace('/s$/u', '', $unit);
        }

        return trim($number.' '.$unit);
    }

    /** @param array{unit_en: string, unit_he: string} $range @return list<string> */
    private static function units(array $range): array
    {
        $own = array_filter([mb_strtolower($range['unit_en']), mb_strtolower(preg_replace('/s$/u', '', $range['unit_en'])), $range['unit_he']]);
        foreach (self::UNITS as $forms) {
            if (array_intersect($own, $forms) !== []) {
                return array_values(array_unique([...$own, ...$forms]));
            }
        }

        return array_values(array_unique($own));
    }

    private static function num(string $number): float
    {
        return (float) str_replace(',', '.', $number);
    }
}
