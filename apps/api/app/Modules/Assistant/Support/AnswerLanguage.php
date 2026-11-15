<?php

namespace App\Modules\Assistant\Support;

/**
 * The language a shopper is answered in, as code reads it: the question's own letters first (Hebrew or Latin), then the
 * language the storefront sent with it, then the shop's primary language, and English when none says. Never Hebrew by
 * default: an English store's "Which snowboard is good for a beginner?" was answered in Hebrew (lets-review-test-4,
 * 15.11). Code only.
 */
final class AnswerLanguage
{
    public const LANGUAGES = ['he', 'en'];

    /** he or en. */
    public static function of(string $question, ?string $sent = null, ?string $shop = null): string
    {
        [$hebrew, $latin] = self::letters($question);

        // a Hebrew question names its brands and codes in Latin ("יש לכם Makita DHP 18V?"): its Hebrew words decide
        if ($hebrew >= 2 || ($hebrew > 0 && $hebrew >= $latin)) {
            return 'he';
        }

        if ($latin > 0) {
            return 'en';
        }

        foreach ([$sent, $shop] as $locale) {
            $locale = strtolower(substr(trim((string) $locale), 0, 2));
            if (in_array($locale, self::LANGUAGES, true)) {
                return $locale;
            }
        }

        return 'en';
    }

    /** The language's name, as the writer is told it. */
    public static function name(string $language): string
    {
        return $language === 'he' ? 'Hebrew' : 'English';
    }

    /**
     * Whether a text is written in the language: Hebrew is a good share of Hebrew letters (an answer naming its products
     * in Latin, "Makita DHP 18V" and "Bosch GSR", is still Hebrew); English has almost none. A text of no letters (a number)
     * is in every language.
     */
    public static function writtenIn(string $text, string $language): bool
    {
        [$hebrew, $latin] = self::letters($text);

        if ($hebrew + $latin === 0) {
            return true;
        }

        return $language === 'he' ? $hebrew >= 0.25 * ($hebrew + $latin) : $hebrew <= 0.1 * ($hebrew + $latin);
    }

    /** @return array{0: int, 1: int} Hebrew letters, Latin letters */
    private static function letters(string $text): array
    {
        return [(int) preg_match_all('/\p{Hebrew}/u', $text), (int) preg_match_all('/[A-Za-z]/', $text)];
    }
}
