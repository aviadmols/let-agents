<?php

namespace App\Modules\Catalog\Contracts;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * A storefront that is closed to visitors answers every address with the same page: Shopify's
 * password page (a development store, and every store Shopify's reviewers test on, is locked by
 * default), WordPress's "Briefly unavailable for scheduled maintenance", a coming-soon or
 * maintenance plugin, an age check that stands in for the whole site. Such a page says nothing
 * about the store: it is never stored, read, sampled, counted as repeating or shown. Whatever
 * reads storefront pages asks here first, in code, no model.
 *
 * A storefront seen locked is remembered for KEEP_DAYS (seen); the scan then reads from what the
 * platform sends (Shopify's API, the WordPress plugin) and the shop is told in plain words. The
 * first product page read normally afterwards opens it again (open).
 */
final class StorefrontLock
{
    public const PASSWORD = 'password';

    public const MAINTENANCE = 'maintenance';

    public const COMING_SOON = 'coming_soon';

    public const AGE_GATE = 'age_gate';

    /** One page behind its own password (a WordPress post or page): that page alone, not the store. */
    public const PROTECTED_PAGE = 'protected_page';

    /** The locks that close the whole storefront. */
    public const WIDE = [self::PASSWORD, self::MAINTENANCE, self::COMING_SOON, self::AGE_GATE];

    /** Refreshed by every reading that finds the store still locked; the nightly site pages read at least. */
    public const KEEP_DAYS = 14;

    /** A lock page is short; a page with more text than this is the store's own, whatever it mentions. */
    private const MAX_TEXT = 2500;

    /** An age check standing in for the whole page says hardly more than its question. */
    private const MAX_GATE_TEXT = 600;

    /**
     * What a lock page says, in the words of Shopify's themes, WordPress and the common plugins, in
     * English and Hebrew. Specific enough that a store's own page never says them by chance.
     */
    private const WORDS = '~(store|shop|site|website)\s+is\s+password[\s-]+protected'
        .'|enter\s+(the\s+)?(store|shop|site)\s+(using\s+|with\s+)?(a\s+|the\s+|your\s+)?password'
        .'|enter\s+(the\s+|your\s+)?(store|shop)\s+password'
        .'|use\s+the\s+password\s+to\s+enter'
        .'|are\s+you\s+the\s+(store|shop)\s+owner\s*\?'
        .'|(store|shop)\s+will\s+be\s+powered\s+by'
        .'|briefly\s+unavailable\s+for\s+scheduled\s+maintenance'
        .'|(site|website|store|shop)\s+is\s+(currently\s+)?(under\s+(construction|maintenance)|down\s+for\s+maintenance)'
        .'|(החנות|האתר)(\s+הזה|\s+הזו|\s+הזאת)?\s+(מוגנ(ת|ה|ים)?|נעול(ה)?)\s+ב?סיסמ'
        .'|סיסמ(ת|ה)\s+(ה)?חנות'
        .'|(כניסה|היכנס(ו)?|להיכנס|הזן|הזינו)\s+(לחנות\s+)?(באמצעות|עם)\s+(ה)?סיסמה'
        .'|הבעלים\s+של\s+החנות\s*\?'
        .'|(האתר|החנות)\s+(נמצא(ת)?\s+)?(ב)?(תחזוקה|בנייה|שיפוצים)'
        .'|(האתר|החנות)\s+(י|ת)יפתח\s+בקרוב~iu';

    private const SOON = '~coming\s+soon|opening\s+soon|launching\s+soon|(י|ת)יפתח\s+בקרוב|בקרוב\s+נפתח~iu';

    /** What a closed page says of itself, beside a plugin's marks. */
    private const CLOSED = '~coming\s+soon|opening\s+soon|launching\s+soon|maintenance|בקרוב|תחזוקה~iu';

    /** Coming-soon and maintenance plugins and builders, by their own marks in the markup. */
    private const PLUGINS = '~seed_?csp|seedprod|wp-maintenance-mode|\bwpmm\b|cmp-coming-soon|elementor-maintenance-mode|maintenance-mode|coming-soon-page|under-construction~i';

    /** The lock a response shows, or null for a page of the store (or no answer at all). */
    public static function of(?Response $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $url = $response->effectiveUri();
        $location = $response->redirect() ? (string) $response->header('Location') : '';

        // Shopify sends a locked storefront's every address to /password; followed or not.
        if (self::passwordPath($location) || ($url !== null && self::passwordPath((string) $url->getPath()))) {
            return self::PASSWORD;
        }

        return self::page((string) $response->body(), null, $response->status());
    }

    /** The lock an HTML page is, or null for a page of the store. */
    public static function page(string $html, ?string $url = null, ?int $status = null): ?string
    {
        if ($url !== null && self::passwordPath((string) parse_url($url, PHP_URL_PATH))) {
            return self::PASSWORD;
        }

        if ($html === '') {
            return null;
        }

        // Shopify's {% form 'storefront_password' %}, its /password form, or the password template's body.
        if (preg_match('~name=["\']form_type["\'][^>]*value=["\']storefront_password["\']|value=["\']storefront_password["\'][^>]*name=["\']form_type["\']~i', $html) === 1
            || preg_match('~<form\b[^>]*action=["\'](?:https?://[^"\'/]+)?/password/?["\']~i', $html) === 1
            || preg_match('~<body\b[^>]*class=["\'][^"\']*\b(template-password|password-page)\b~i', $html) === 1) {
            return self::PASSWORD;
        }

        $text = self::visible($html);
        $short = mb_strlen($text) <= self::MAX_TEXT;
        $words = preg_match(self::WORDS, $text) === 1;

        if (preg_match('~<input\b[^>]*type=["\']password["\'][^>]*name=["\']password["\']|<input\b[^>]*name=["\']password["\'][^>]*type=["\']password["\']~i', $html) === 1 && $words) {
            return self::PASSWORD;
        }

        // WordPress's own: "Briefly unavailable for scheduled maintenance", answered 503.
        if (preg_match('~briefly\s+unavailable\s+for\s+scheduled\s+maintenance~iu', $text) === 1) {
            return self::MAINTENANCE;
        }

        // A post or page behind its own password: that page alone is closed.
        if (preg_match('~<form\b[^>]*class=["\'][^"\']*\bpost-password-form\b|action=["\'][^"\']*wp-login\.php\?action=postpass~i', $html) === 1) {
            return self::PROTECTED_PAGE;
        }

        // A plugin's coming-soon or maintenance page: its marks, and a short page or a 503.
        if (preg_match(self::PLUGINS, $html) === 1 && ($status === 503 || ($short && ($words || preg_match(self::CLOSED, $text) === 1)))) {
            return preg_match(self::SOON, $text) === 1 ? self::COMING_SOON : self::MAINTENANCE;
        }

        // An age check that stands in for the whole page: almost no text and nothing to buy. One shown over
        // the page (a wine store's product page with its age popup) leaves the page there, and is no lock.
        if (mb_strlen($text) <= self::MAX_GATE_TEXT && preg_match('~\b(age-gate|age_gate|agegate|age-verification|age-verify)\b~i', $html) === 1
            && preg_match('~add[-_]to[-_]cart|/cart/add|single_add_to_cart|itemprop=["\']price["\']~i', $html) !== 1
            && preg_match('~\b(18|21)\b|years?\s+old|your\s+age|legal\s+age|גיל|מעל\s+(גיל\s+)?18~iu', $text) === 1) {
            return self::AGE_GATE;
        }

        if ($short && $words) {
            return preg_match('~password|סיסמ~iu', $text) === 1 ? self::PASSWORD
                : (preg_match(self::SOON, $text) === 1 ? self::COMING_SOON : self::MAINTENANCE);
        }

        return null;
    }

    /** Text kept before this check (a page's title and text, a sentence): a lock page's words. */
    public static function text(string $text): bool
    {
        return mb_strlen($text) <= self::MAX_TEXT && preg_match(self::WORDS, $text) === 1;
    }

    /** Whether the lock closes the whole storefront, not one page. */
    public static function wide(?string $kind): bool
    {
        return in_array($kind, self::WIDE, true);
    }

    /** The storefront was found locked: remembered, so the scan and the shop's notices know. */
    public static function seen(string $shopId, string $kind): void
    {
        Cache::put(self::key($shopId), ['kind' => $kind, 'at' => now()->toIso8601String()], now()->addDays(self::KEEP_DAYS));
    }

    /** A page of the store was read as its shoppers see it: the storefront is open. */
    public static function open(string $shopId): void
    {
        Cache::forget(self::key($shopId));
    }

    /** @return array{kind: string, at: string}|null how the storefront was last found locked; null when open */
    public static function state(string $shopId): ?array
    {
        $state = Cache::get(self::key($shopId));

        return is_array($state) && isset($state['kind'], $state['at']) ? ['kind' => (string) $state['kind'], 'at' => (string) $state['at']] : null;
    }

    private static function passwordPath(string $path): bool
    {
        $path = (string) parse_url($path, PHP_URL_PATH);

        return preg_match('~^(/[a-z]{2}(-[a-z]{2})?)?/password/?$~i', $path) === 1;
    }

    /** The words a visitor reads, without scripts, styles or markup. */
    private static function visible(string $html): string
    {
        $html = (string) preg_replace('~<(script|style|noscript|svg|template)\b[^>]*>.*?</\1\s*>~isu', ' ', $html);
        $text = html_entity_decode(strip_tags((string) preg_replace('~<(br|p|div|li|h[1-6]|section|form|label|button)\b~iu', "\n<$1", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/^\s+|\s+$/u', '', (string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function key(string $shopId): string
    {
        return 'catalog:storefront-locked:'.$shopId;
    }
}
