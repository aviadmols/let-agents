<?php

namespace App\Core\Localization;

/**
 * The admin languages and what the kernel needs to know about them.
 */
final class Locales
{
    /** @return list<string> */
    public static function supported(): array
    {
        return array_values(config('upsell.locales', ['he', 'en']));
    }

    public static function fallback(): string
    {
        return self::supported()[0];
    }

    /**
     * What $write gives in each language, by locale: a report written in code, ready in whichever language
     * its reader's account is.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return array<string, T>
     */
    public static function each(callable $write): array
    {
        $was = app()->getLocale();
        $written = [];

        try {
            foreach (self::supported() as $locale) {
                app()->setLocale($locale);
                $written[$locale] = $write();
            }
        } finally {
            app()->setLocale($was);
        }

        return $written;
    }

    /**
     * The language a shop's built-in words are read in, from its content language (shops.content_locale): its own
     * when the words exist in it, else English; Hebrew for a shop that never said. A French or an Arabic store
     * reads English defaults, never Hebrew ones.
     */
    public static function forShop(?string $contentLocale): string
    {
        $language = strtolower(explode('-', str_replace('_', '-', trim((string) $contentLocale)))[0]);
        $language = $language === 'iw' ? 'he' : $language;

        return match (true) {
            $language === '' => self::isSupported('he') ? 'he' : self::fallback(),
            self::isSupported($language) => $language,
            default => self::isSupported('en') ? 'en' : self::fallback(),
        };
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::supported(), true);
    }

    public static function direction(string $locale): string
    {
        $language = strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        return in_array($language, config('upsell.rtl_locales', []), true) ? 'rtl' : 'ltr';
    }

    /**
     * The best supported locale for an Accept-Language header, or null when none of the
     * browser's languages is supported.
     */
    public static function negotiate(?string $acceptLanguage): ?string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $acceptLanguage) as $index => $part) {
            $pieces = explode(';', trim($part));
            $language = strtolower(explode('-', trim($pieces[0]))[0]);
            $quality = 1.0;

            foreach (array_slice($pieces, 1) as $parameter) {
                if (str_starts_with(trim($parameter), 'q=')) {
                    $quality = (float) substr(trim($parameter), 2);
                }
            }

            // Hebrew is still sent as "iw" by some older Android browsers.
            $language = $language === 'iw' ? 'he' : $language;

            if ($quality > 0 && self::isSupported($language)) {
                $candidates[] = [$language, $quality, $index];
            }
        }

        usort($candidates, fn (array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        return $candidates[0][0] ?? null;
    }
}
