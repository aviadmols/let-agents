<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * What code can confirm of a source a model named, so that a value is never believed on the model's
 * word: that the address is one the search returned, that the page really holds the sentence, that
 * the sentence says the value, and which sites are independent of each other and of the store.
 * No model, no network here; the caller fetches the page.
 */
final class WebEvidence
{
    /** A sentence shorter than this proves nothing. */
    public const MIN_QUOTE = 12;

    /** The part of a page read: a product page says what it says near the top. */
    public const MAX_PAGE = 400_000;

    /** Second-level labels that belong to the country, so "makita.co.il" is the site, not "co.il". */
    private const SECOND_LEVEL = ['co', 'com', 'org', 'net', 'gov', 'ac', 'org', 'muni'];

    public static function host(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ! isset($parts['host'])) {
            return null;
        }

        return strtolower(trim($parts['host'], '.'));
    }

    /** The site, not the page's subdomain: "www.shop.makita.co.il" is "makita.co.il". */
    public static function site(string $host): string
    {
        $labels = explode('.', preg_replace('/^www\./', '', strtolower($host)) ?? $host);
        $take = count($labels) >= 3 && strlen((string) end($labels)) === 2 && in_array($labels[count($labels) - 2], self::SECOND_LEVEL, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    /** @param  string  $list  "makita.com, bosch.com" */
    public static function inList(string $host, string $list): bool
    {
        $site = self::site($host);

        foreach (preg_split('/[\s,;]+/', mb_strtolower($list), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $domain) {
            if (self::site($domain) === $site) {
                return true;
            }
        }

        return false;
    }

    /** Text of a page, tags and entities gone, lowercase, one space between words. */
    public static function plain(string $html): string
    {
        $html = preg_replace('~<(script|style|noscript)\b.*?</\1>~is', ' ', substr($html, 0, self::MAX_PAGE)) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /** Whether the page holds the sentence, word for word (spacing and case aside). */
    public static function quoteOn(string $pageText, string $quote): bool
    {
        $needle = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(html_entity_decode($quote, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

        return mb_strlen($needle) >= self::MIN_QUOTE && str_contains($pageText, $needle);
    }

    /**
     * Whether a sentence says a number: any number in it equals the value, or the value in the unit
     * a thousand times larger or smaller (grams for kilograms, millimetres for metres).
     */
    public static function says(string $quote, float $value): bool
    {
        preg_match_all('/\d+(?:[.,]\d+)?/u', $quote, $found);

        foreach ($found[0] as $text) {
            $number = (float) str_replace(',', '.', $text);

            foreach ([1.0, 1000.0, 0.001] as $scale) {
                if (abs($number * $scale - $value) < max(1e-9, abs($value) * 1e-6)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a sentence names a choice's value: by one of the value's patterns or by its name in
     * either language.
     *
     * @param  array{key: string, label?: array<string, string>, patterns?: list<string>}  $option
     */
    public static function names(string $quote, array $option): bool
    {
        foreach ((array) ($option['patterns'] ?? []) as $pattern) {
            if (@preg_match('~'.$pattern.'~iu', $quote) === 1) {
                return true;
            }
        }

        foreach ((array) ($option['label'] ?? []) as $label) {
            if ($label !== '' && mb_stripos($quote, (string) $label) !== false) {
                return true;
            }
        }

        return false;
    }
}
