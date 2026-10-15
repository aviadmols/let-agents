<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * Where a vocabulary's pattern names something in a text, as a whole word. A pattern is the
 * shop's word ("ספיר"); found inside a longer word ("ספירלה", a spiral) it names nothing, and a
 * spiral ring would be read as a sapphire one. A Hebrew word may carry up to two of the letters
 * Hebrew joins before a word ("וה", "ב", "מ"…) and one of the short endings of its plural or its
 * feminine ("ספירים", "משובצת"); any other letter around it makes it part of another word.
 *
 * Every reading of a vocabulary's patterns in code goes through here: the code facts, the digest
 * the models get, and the coverage a proposal is measured by.
 */
final class PatternMatch
{
    /** Letters Hebrew joins before a word: and, the, in, to, from, that, as. */
    private const PREFIX = '/^[והבלמשכ]{1,2}$/u';

    /** Endings a word may carry and stay itself: plural, feminine, construct, an English plural. */
    private const SUFFIXES = ['', 'ים', 'ות', 'יות', 'ית', 'ת', 'ה', 'י', 'יים', 's', 'es'];

    /**
     * Words that name a value and also something else, and the words around them that mean the
     * something else: "חום" is brown, and heat ("מוריד את החום", "עמיד בחום", "בידוד חום",
     * "חום גבוה", "חום וקור"). Read there, it names no colour.
     *
     * @var array<string, array{before: string, after: string}> by the word's stem
     */
    private const OTHER_MEANING = [
        'חום' => [
            'before' => '/(?:^|[\s,(])(?:מוריד|מורידה|מורידים|להוריד|הורדת|מפחית|מפחיתה|להפחית|הפחתת|מונע|מונעת|עמיד|עמידה|עמידים|עמידות|חסין|חסינה|חסינות|בידוד|מבודד|מבודדת|מבודדים|מחזיר|מחזירה|החזר|הולכת|מוליך|העברת|נגד|מפני|דרגת|טמפרטורת|פיזור|מפזר|מקור|גלי|מכת|קרינת|לחץ|את)\s*$/u',
            'after' => '/^\s*(?:גבוה|גבוהה|גבוהים|קיצוני|קיצונית|השמש|שמש|קרינה|הקרינה|וקור|ו-?קור|עד\s*\d|של\s*\d|\d)/u',
        ],
    ];

    /**
     * The first whole-word match of any of the patterns, in their order: [offset, length] in
     * bytes, or null.
     *
     * @param  list<string>  $patterns
     * @return array{0: int, 1: int}|null
     */
    public static function first(string $text, array $patterns): ?array
    {
        if ($text === '') {
            return null;
        }

        foreach ($patterns as $pattern) {
            if (@preg_match_all('~'.$pattern.'~imu', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$found, $offset]) {
                    if ($found !== '' && self::whole($text, (int) $offset, strlen($found)) && ! self::otherMeaning($text, (int) $offset, $found)) {
                        return [(int) $offset, strlen($found)];
                    }
                }
            }
        }

        return null;
    }

    /** @param list<string> $patterns */
    public static function any(string $text, array $patterns): bool
    {
        return self::first($text, $patterns) !== null;
    }

    /** Whether a word that also means something else is used in that other meaning here ("מוריד את החום"). */
    public static function otherMeaning(string $text, int $offset, string $found): bool
    {
        foreach (self::OTHER_MEANING as $stem => $around) {
            if (! str_starts_with(mb_strtolower($found), $stem)) {
                continue;
            }

            // The words before it, without the letters joined to it ("ה" in "החום", "ב" in "בחום").
            $before = (string) preg_replace('/\p{L}*\z/u', '', substr($text, 0, $offset));
            $after = substr($text, $offset + strlen($found));

            return preg_match($around['before'], $before) === 1 || preg_match($around['after'], $after) === 1;
        }

        return false;
    }

    /** Whether the match at $offset stands as a word of its own: only a prefix before it, only an ending after. */
    public static function whole(string $text, int $offset, int $length): bool
    {
        // \z, not $: a $ also stands before a last newline, and would glue the line above to the word.
        preg_match('/(\p{L}*)\z/u', substr($text, 0, $offset), $before);
        preg_match('/^(\p{L}*)/u', substr($text, $offset + $length), $after);
        $before = $before[1] ?? '';
        $after = mb_strtolower($after[1] ?? '');

        return ($before === '' || preg_match(self::PREFIX, $before) === 1) && in_array($after, self::SUFFIXES, true);
    }
}
