<?php

namespace App\Modules\Search\Support;

/**
 * A Latin name as Hebrew writes it, so a shopper typing it in Hebrew finds it: "LEGO" is "ליגו" and "לגו", "Herschel"
 * "הרשל", "Vagabond" "וגבונד". Code only, the same every time. Two spellings each: with the vowels a Hebrew writer marks
 * (i and e as י, o and u as ו) and with only o and u marked; the search's own tolerance for a letter more or less does the
 * rest. The vowels keep apart what sounds apart: "Logo" is "לוגו", never "ליגו".
 */
final class Transliterate
{
    /** English words that are no name: never given a Hebrew spelling. */
    private const PLAIN = ['and', 'the', 'for', 'with', 'from', 'size', 'pack', 'set', 'new', 'mini', 'classic', 'short', 'long',
        'sleeve', 'black', 'white', 'blue', 'red', 'green', 'pink', 'grey', 'gray', 'brown', 'beige', 'cream', 'women', 'men', 'kids',
        'baby', 'girls', 'boys', 'unisex', 'shirt', 'tee', 'shoe', 'shoes', 'bag', 'cap', 'one', 'two', 'top', 'low', 'high', 'mid'];

    /** Letters read together first: "sch" is ש, "ck" is ק. */
    private const PAIRS = ['sch' => 'ש', 'tch' => 'צ', 'sh' => 'ש', 'ch' => 'צ', 'th' => 'ת', 'ph' => 'פ', 'ck' => 'ק', 'qu' => 'קו', 'ee' => 'י', 'oo' => 'ו', 'ou' => 'ו', 'ea' => 'י', 'ai' => 'י', 'ay' => 'י', 'ey' => 'י'];

    private const LETTERS = ['b' => 'ב', 'c' => 'ק', 'd' => 'ד', 'f' => 'פ', 'g' => 'ג', 'h' => 'ה', 'j' => 'ג', 'k' => 'ק', 'l' => 'ל', 'm' => 'מ',
        'n' => 'נ', 'p' => 'פ', 'q' => 'ק', 'r' => 'ר', 's' => 'ס', 't' => 'ט', 'v' => 'ו', 'w' => 'ו', 'x' => 'קס', 'z' => 'ז'];

    /**
     * The Hebrew spellings of a name's Latin words, each at least three letters and none a plain English word.
     *
     * @return list<string>
     */
    public static function hebrew(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) < 3 || in_array($word, self::PLAIN, true)) {
                continue;
            }
            foreach ([self::spell($word, true), self::spell($word, false)] as $spelled) {
                if (mb_strlen($spelled) >= 3) {
                    $out[$spelled] = true;
                }
            }
        }

        return array_keys($out);
    }

    /** One spelling: $vowels marks i and e as י too ("ליגו"); without, only o and u as ו ("לגו"). */
    private static function spell(string $word, bool $vowels): string
    {
        $out = '';
        $at = 0;
        $length = strlen($word);
        while ($at < $length) {
            foreach (self::PAIRS as $pair => $hebrew) {
                if (substr($word, $at, strlen($pair)) === $pair) {
                    $out .= ($hebrew === 'י' && ! $vowels && $at > 0) ? '' : $hebrew;
                    $at += strlen($pair);

                    continue 2;
                }
            }
            $letter = $word[$at];
            // a letter written twice is said once: "Small" is "סמל", "Melissa" "מליסה"
            if ($at > 0 && $word[$at - 1] === $letter && ! str_contains('aeiouy', $letter)) {
                $at++;

                continue;
            }
            $first = $at === 0;
            $last = $at === $length - 1;
            // "c" before e, i or y sounds as s ("cell"), else as k
            $next = $word[$at + 1] ?? '';
            $out .= match (true) {
                $letter === 'a' => $first ? 'א' : ($last ? 'ה' : ''),
                $letter === 'e' => $first ? 'א' : ($last ? 'ה' : ($vowels ? 'י' : '')),
                $letter === 'i', $letter === 'y' => $first ? 'אי' : ($vowels || $last ? 'י' : ''),
                $letter === 'o', $letter === 'u' => $first ? 'או' : 'ו',
                $letter === 'c' && in_array($next, ['e', 'i', 'y'], true) => 'ס',
                default => self::LETTERS[$letter] ?? '',
            };
            $at++;
        }

        return $out;
    }
}
