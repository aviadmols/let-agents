<?php

namespace App\Modules\Assistant\Support;

/**
 * The sentences a store repeats on most of its product pages: "החזר כספי מלא תוך 14 יום",
 * "אפשרות לשריין מוצר באמצעות פריסת תשלומים". The theme prints them beside every product, so they are
 * about the store, not the product, and no product description holds them. Read by code only.
 */
final class RepeatedText
{
    private const MIN_CHARS = 8;

    private const MAX_CHARS = 300;

    /** The page's sentences, once each: the main part when the page marks one, without menus or scripts. */
    public static function sentences(string $html): array
    {
        $html = (string) preg_replace('#<(script|style|noscript|svg|template|select|video|audio|iframe|object|nav|header|footer)\b[^>]*>.*?</\1\s*>#isu', ' ', $html);

        if (preg_match('#<main\b[^>]*>(.*)</main>#isu', $html, $main) === 1) {
            $html = $main[1];
        }

        // Every block ends a line; inline tags join their text.
        $html = (string) preg_replace('#</?(br|p|div|li|ul|ol|h[1-6]|section|article|aside|tr|td|th|dd|dt|dl|summary|details|figcaption|blockquote|label|table)\b[^>]*>#iu', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sentences = [];

        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}]+/u', ' ', $line));

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $sentence) {
                $sentence = trim((string) $sentence);

                if (self::worth($sentence)) {
                    $sentences[self::key($sentence)] = $sentence;
                }
            }
        }

        return $sentences;
    }

    /**
     * The sentences found on at least $share of the pages, and on three at the least, the most
     * repeated first.
     *
     * @param  list<array<string, string>>  $pages  each page's sentences, as sentences() gives them
     * @return list<array{text: string, pages: int}>
     */
    public static function repeated(array $pages, float $share, int $limit = 40): array
    {
        $count = [];
        $text = [];
        $first = [];

        foreach ($pages as $sentences) {
            foreach ($sentences as $key => $sentence) {
                $count[$key] = ($count[$key] ?? 0) + 1;
                $text[$key] ??= $sentence;
                $first[$key] ??= count($first);
            }
        }

        // "עבור לפריט 1", "עבור לפריט 2": a gallery's labels, the same words with a running number.
        $numbered = [];
        foreach (array_keys($count) as $key) {
            $numbered[(string) preg_replace('/\d+/u', '#', $key)][] = $key;
        }
        $labels = array_merge([], ...array_values(array_filter($numbered, fn (array $keys): bool => count($keys) >= 3)));

        $needed = max(3, (int) ceil(count($pages) * $share));
        $kept = array_values(array_diff(array_keys(array_filter($count, fn (int $n): bool => $n >= $needed)), $labels));
        // The most repeated first; among equals, in the order the page shows them.
        usort($kept, fn (string $a, string $b): int => [$count[$b], $first[$a]] <=> [$count[$a], $first[$b]]);

        return array_map(fn (string $key): array => ['text' => $text[$key], 'pages' => $count[$key]], array_slice($kept, 0, $limit));
    }

    /** What a store's word about its service is about: delivery, returns, warranty, payment, a discount, its hours. */
    private const SERVICE = '/משלוח|שליח|אספק|איסוף|החזר|החלפ|ביטול|אחריות|תשלומ|תשלום|אשראי|הנחה|קופון|מבצע|חינם|שעות|פתוח|סגור|טלפון|וואטסאפ|כתובת החנות|'
        .'deliver|shipping|ship|pickup|return|refund|exchange|warrant|payment|instal|discount|coupon|free|hours|open|reply|respond/iu';

    /** A number that says how much, how long or how many: 199₪, 14 יום, 12 חודשים, 15%. */
    private const MEASURE = '/\d+\s*(?:₪|ש"ח|שח|%|\$|€|יום|ימים|ימי|שעות|שבוע|שבועות|חודש|חודשים|שנה|שנים|תשלומים|days?|hours?|weeks?|months?|years?)|\d+\s*(?:business|working)\s+days?|\d+\s*ימי\s+עסקים|(?:₪|\$|€)\s*\d/iu';

    /**
     * A sentence the store repeats that tells a shopper something about its service: delivery, returns, warranty,
     * payment, a discount, its hours, said with a measure or in a full sentence of five words or more. Not a heading,
     * a question, a button or a line of its screens ("העגלה שלך ריקה", "האם הספר מתאים לי?", "לכל הספרים שלנו",
     * "הצטרפו לקהילת התוכן"), nor the store's slogan: those are on every page and answer nothing (Sela Meir, 29.10:
     * 21 repeated sentences, two of them the store's word). Code only.
     */
    public static function fact(string $sentence): bool
    {
        $sentence = trim((string) preg_replace('/^-\s*/u', '', $sentence));
        $words = count(preg_split('/\s+/u', $sentence) ?: []);

        if ($sentence === '' || preg_match('/[?:？]\s*$/u', $sentence) === 1 || $words < 4) {
            return false;
        }

        return (preg_match(self::SERVICE, $sentence) === 1 && ($words >= 5 || preg_match(self::MEASURE, $sentence) === 1))
            || preg_match(self::MEASURE, $sentence) === 1;
    }

    /** The site's lines on cookies, tracking and personal data: its privacy page holds them. */
    private const LEGAL = '/cookie|עוגי|קובצי|tracking|pixel|personal(?:ly)?\s+(?:identifiable\s+)?(?:information|data)|מידע\s+אישי|פרטים\s+אישיים|gdpr|consent|הסכמה\s+ל|privacy|פרטיות/iu';

    /**
     * A fact worth keeping as the store's knowledge: not the site's legal lines on cookies, tracking and personal data
     * (Gil Travel, 29.10: most of what its posts and pages said was its privacy policy).
     */
    public static function useful(string $fact): bool
    {
        return preg_match(self::LEGAL, $fact) !== 1;
    }

    /**
     * The facts, each once: one that says what another already said (most of its words) goes, the first kept (Sela
     * Meir, 29.10: the 30 days to exchange, from the terms and again from the payment page).
     *
     * @param  list<string>  $facts
     * @return list<string>
     */
    public static function distinct(array $facts, float $same = 0.6): array
    {
        $kept = [];
        $words = [];
        foreach ($facts as $fact) {
            // the page it came from ("(תקנון)") is no part of what it says
            $mine = array_values(array_unique(array_filter(explode(' ', self::key((string) preg_replace('/\s*\([^()]*\)\s*$/u', '', $fact))), fn (string $w): bool => mb_strlen($w) > 2)));
            if ($mine === []) {
                continue;
            }
            foreach ($words as $theirs) {
                $shared = count(array_intersect($mine, $theirs));
                if ($shared / min(count($mine), count($theirs)) >= $same && $shared / max(count($mine), count($theirs)) >= $same * 0.75) {
                    continue 2;
                }
            }
            $kept[] = $fact;
            $words[] = $mine;
        }

        return $kept;
    }

    public static function key(string $sentence): string
    {
        return mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $sentence)));
    }

    /** A sentence a shopper would read: some words, mostly letters, not a lone number or a label. */
    private static function worth(string $sentence): bool
    {
        $length = mb_strlen($sentence);

        return $length >= self::MIN_CHARS && $length <= self::MAX_CHARS
            && preg_match_all('/\p{L}/u', $sentence) >= 6
            && count(preg_split('/\s+/u', $sentence) ?: []) >= 3;
    }
}
