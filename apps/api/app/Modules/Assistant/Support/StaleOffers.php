<?php

namespace App\Modules\Assistant\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * An offer told in a post long ago is no offer today (Sella, 12.11: a 2024 post on the book week's sales answered "what
 * offers do you have?" in 2026). What a post says of a sale, a discount, a coupon or a deal is not used once the post is
 * older than the store's limit (assistant.offer_max_age_days): those lines leave it, the rest of it stays. Pages are not
 * posts: a returns policy or "no double discounts" holds until the store changes it. Code only, any store, Hebrew and
 * English.
 */
final class StaleOffers
{
    /**
     * Words of an offer: a sale, a discount, a coupon, a deal, a gift with a purchase, "1+1", "6 ספרים ב־200".
     * Whole words, a prefix letter or two allowed in Hebrew.
     */
    private const OFFER = '/(?<![\p{L}])(?:[והבלמש]{0,2}(?:מבצע|מבצעים|הנחה|הנחות|קופון|קופונים|סייל|הטבה|הטבות|הוזלה|הוזלות|מתנה\s+בקנייה|קוד\s+הנחה)|sale|sales|discount|discounts|discounted|coupon|coupons|promo|promos|promotion|promotions|deal|deals|offer|offers|black\s+friday|% off|percent off)(?![\p{L}])|\d+\s*\+\s*\d+|\d+\s+\p{L}+\s+ב[-־]?\s*₪?\s*\d+|\d+\s*%\s*(?:הנחה|off)/iu';

    /** Whether a text tells of an offer. */
    public static function talks(string $text): bool
    {
        return preg_match(self::OFFER, $text) === 1;
    }

    /** A text without its sentences and lines that tell of an offer. */
    public static function withoutOffers(string $text): string
    {
        $kept = [];
        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            $sentences = array_values(array_filter(preg_split('/(?<=[.!?])\s+/u', $line) ?: [], fn (string $s): bool => ! self::talks($s)));
            if ($sentences !== []) {
                $kept[] = implode(' ', $sentences);
            }
        }

        return trim(implode("\n", array_filter(array_map('trim', $kept), fn (string $l): bool => $l !== '')));
    }

    /**
     * When a post was published: by the date its address carries ("/2024/06/09/"), which an edit never moves; else when
     * it was last changed. Null when neither is known.
     */
    public static function publishedAt(?string $url, ?CarbonInterface $changed): ?CarbonInterface
    {
        if (is_string($url) && preg_match('~/(20\d{2}|19\d{2})/(\d{1,2})/(?:(\d{1,2})/)?~', $url, $m) === 1 && checkdate((int) $m[2], (int) ($m[3] ?? 1) ?: 1, (int) $m[1])) {
            return Carbon::create((int) $m[1], (int) $m[2], (int) (($m[3] ?? '') !== '' ? $m[3] : 1))->startOfDay();
        }

        return $changed;
    }
}
