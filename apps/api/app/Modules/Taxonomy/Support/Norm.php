<?php

namespace App\Modules\Taxonomy\Support;

/**
 * Words as tags and evidence are compared: lower case, final letters as any other, the leading
 * ה, plural endings and punctuation aside, words sorted ("ספרי אימה" and "אימה, ספרים" are one).
 * Code only.
 */
final class Norm
{
    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    public static function label(string $text): string
    {
        $words = array_map(function (string $w): string {
            $w = mb_strlen($w) > 3 && mb_substr($w, 0, 1) === 'ה' ? mb_substr($w, 1) : $w;

            return mb_strlen($w) > 4 ? (string) preg_replace('/(?:ימ|ות|s)$/u', '', $w) : $w;
        }, self::words($text));
        sort($words, SORT_STRING);

        return mb_substr(implode(' ', $words), 0, 120);
    }

    /** Whether the evidence, as words, is in the text: a quote need not keep its punctuation or final letters. */
    public static function quoted(string $evidence, string $text): bool
    {
        $quote = implode(' ', self::words($evidence));

        return mb_strlen($quote) >= 3 && str_contains(' '.implode(' ', self::words($text)).' ', $quote);
    }

    /**
     * Whether a passage given as evidence stands in the text, as a reader would say it does: word for
     * word, or with most of its words there in about its order (a model that rephrases a few words of a
     * real sentence has not made it up; one whose words the text does not hold has). The tag's own name
     * is never asked for: a book on leadership that never writes "leadership" is justified by what it says.
     */
    public static function grounded(string $evidence, string $text, float $share = 0.8): bool
    {
        if (self::quoted($evidence, $text)) {
            return true;
        }

        $quote = array_values(array_filter(self::words($evidence), fn (string $w): bool => mb_strlen($w) >= 2));
        if (count($quote) < 3) {
            return false;
        }

        // each word found after the one before it, a word or two of the passage allowed to be missing
        $words = self::words($text);
        $at = 0;
        $found = 0;
        foreach ($quote as $word) {
            for ($i = $at; $i < count($words); $i++) {
                if ($words[$i] === $word || (mb_strlen($word) > 3 && str_contains($words[$i], $word))) {
                    $found++;
                    $at = $i + 1;
                    break;
                }
            }
        }

        return $found / count($quote) >= $share;
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', strtr(mb_strtolower($text), self::FINALS)) ?: [], fn (string $w): bool => $w !== ''));
    }

    /** A key from a label: latin letters and digits, the Hebrew kept as it is. */
    public static function key(string $label): string
    {
        return mb_substr(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_strtolower(trim($label))), '_'), 0, 80) ?: 'tag';
    }
}
