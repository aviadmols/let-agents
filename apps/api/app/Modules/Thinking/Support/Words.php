<?php

namespace App\Modules\Thinking\Support;

/**
 * Hebrew (and English) words as a set request and a product's text are compared: without final
 * letters, prefixes (ו ה ב ל מ ש כ), plural and construct endings, and with the vowel letters ו and
 * י dropped inside a word, so "החטופים", "חטוף" and "נחטף" meet at "חטפ", and "ספרי" meets "ספרים".
 * Code only.
 */
final class Words
{
    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    /** Letters a Hebrew word may open with before its stem (the passive נ too: "נחטף"). */
    private const PREFIXES = 'והבלמשכנ';

    /** @return list<string> the words of a text, lower case, final letters as any other */
    public static function of(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', strtr(mb_strtolower($text), self::FINALS)) ?: [], fn (string $w): bool => $w !== ''));
    }

    /**
     * A word's stems, the longest first: "החטופים" gives החטופימ, חטופימ, חטופ, חטפ.
     *
     * @return list<string>
     */
    public static function stems(string $word): array
    {
        $forms = self::forms($word);

        foreach ($forms as $form) {
            $forms[] = self::dry($form);
        }

        return self::longestFirst($forms);
    }

    /** The word without its prefix and ending, as most of its forms hold it: "ספרי" → "ספר", "ספריית" → "ספריי". */
    public static function core(string $word): string
    {
        $forms = self::longestFirst(self::forms($word));

        return $forms === [] ? strtr(mb_strtolower($word), self::FINALS) : (string) end($forms);
    }

    /**
     * What a database is asked to hold, by LIKE, for a word: its stems as a text keeps them, a stem's
     * last letter also in its final form ("חטופ" also "חטוף", "חטפ" also "חטף").
     *
     * @return list<string>
     */
    public static function likes(string $word): array
    {
        $out = [];

        foreach (self::stems($word) as $stem) {
            $out[] = $stem;
            $final = array_search(mb_substr($stem, -1), self::FINALS, true);
            if (is_string($final)) {
                $out[] = mb_substr($stem, 0, -1).$final;
            }
        }

        return array_values(array_unique($out));
    }

    /** The word without ו and י after its first letter: "חטופ" → "חטפ". */
    public static function dry(string $word): string
    {
        return mb_substr($word, 0, 1).str_replace(['ו', 'י'], '', mb_substr($word, 1));
    }

    /** A text as it is searched: its words, and each again dry, so a stem meets either spelling. */
    public static function searchable(string $text): string
    {
        $words = self::of($text);

        return ' '.implode(' ', $words).' '.implode(' ', array_map(self::dry(...), $words)).' ';
    }

    /**
     * Whether a searchable text has a word that begins with one of the stems, after a prefix or two.
     *
     * @param  list<string>  $stems
     */
    public static function has(string $searchable, array $stems): bool
    {
        foreach ($stems as $stem) {
            if (preg_match('/ ['.self::PREFIXES.']{0,2}'.preg_quote($stem, '/').'/u', $searchable) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The word, without a prefix or two while three letters stay ("והספרים" → "ספרים"), and each of
     * those without its ending ("ספרים" → "ספר", "ספרי" → "ספר").
     *
     * @return list<string>
     */
    private static function forms(string $word): array
    {
        $word = strtr(mb_strtolower($word), self::FINALS);
        $forms = [$word];
        $bare = $word;

        for ($i = 0; $i < 2 && mb_strlen($bare) > 3 && str_contains('והבלמשכ', mb_substr($bare, 0, 1)); $i++) {
            $bare = mb_substr($bare, 1);
            $forms[] = $bare;
        }

        foreach ($forms as $form) {
            $cut = preg_replace('/(?:יות|ימ|ות|י|ה|ת)$/u', '', $form);
            if (is_string($cut) && $cut !== $form && mb_strlen($cut) >= 3) {
                $forms[] = $cut;
            }
        }

        return array_values(array_filter($forms, fn (string $f): bool => mb_strlen($f) >= 3));
    }

    /**
     * @param  list<string>  $forms
     * @return list<string>
     */
    private static function longestFirst(array $forms): array
    {
        $forms = array_values(array_unique(array_filter($forms, fn (string $f): bool => mb_strlen($f) >= 3)));
        usort($forms, fn (string $a, string $b): int => [mb_strlen($b), $a] <=> [mb_strlen($a), $b]);

        return $forms;
    }
}
