<?php

namespace App\Modules\Demo\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A store's public site, read the way a browser reads it, for a demo: only its own host, only when that host is a
 * public address (never a private or reserved one), politely (a name of our own, a short wait, no retries). What it
 * runs on, its name, its language and currency, and a page's words. Code only.
 */
final class PublicSite
{
    public const AGENT = 'LetsDemo/1.0 (+https://lets.co.il)';

    private const TIMEOUT = 20;

    public const SHOPIFY = 'shopify';

    public const WOOCOMMERCE = 'woocommerce';

    /** WordPress with no store: a content site, read by its content types (WordPressSite). */
    public const WORDPRESS = 'wordpress';

    public const OTHER = 'other';

    /** "https://www.Store.co.il/shop?x=1" → "store.co.il"; null when it is no address. */
    public static function host(string $url): ?string
    {
        $url = trim($url);
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        if (! is_string($host) || ! str_contains($host, '.') || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return null;
        }

        return (string) preg_replace('/^www\./', '', strtolower(rtrim($host, '.')));
    }

    /** Whether the host is a public address on the internet: never ours to reach inside (a private or reserved range). */
    public static function public(string $host): bool
    {
        if (app()->runningUnitTests()) {
            return true;
        }

        $ips = gethostbynamel($host) ?: [];

        return $ips !== [] && array_filter($ips, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) === [];
    }

    /** A page of the host (a path, or a whole address on it); null when it does not answer. */
    public static function get(string $host, string $path, array $query = []): ?Response
    {
        $url = str_starts_with($path, 'http') ? $path : 'https://'.$host.'/'.ltrim($path, '/');

        // only the store's own host, with or without www
        if (self::host($url) !== $host) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(10)->withHeaders(['User-Agent' => self::AGENT, 'Accept-Language' => 'he,en;q=0.8'])->get($url, $query);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response : null;
    }

    /** JSON from the host, or null when it is not JSON (a store that hides its feed answers HTML). */
    public static function json(string $host, string $path, array $query = []): ?array
    {
        $response = self::get($host, $path, $query);
        $data = $response?->json();

        return is_array($data) ? $data : null;
    }

    /**
     * Whether the site runs on what Lets connects to, Shopify or WordPress (a store on WooCommerce or a content site):
     * its home page says so (Shopify's CDN, WordPress's folders or generator), else its Shopify product feed or its
     * WordPress API answers. A site on anything else gets no demo it could never connect as.
     */
    public static function supported(string $host): bool
    {
        $html = (string) self::get($host, '/')?->body();
        if (preg_match('~cdn\.shopify\.com|Shopify\.theme|/wp-content/|/wp-includes/|<meta[^>]+generator[^>]+WordPress~i', $html) === 1) {
            return true;
        }

        if (is_array(self::json($host, '/products.json', ['limit' => 1])['products'] ?? null)) {
            return true;
        }

        return isset(self::json($host, '/wp-json/')['namespaces']);
    }

    /**
     * What the store runs on: Shopify when its public product feed answers, WooCommerce when its public store API does,
     * else other (read by its sitemap). With its name, language and currency from the home page, and how many products
     * it has in all when its feed says (WooCommerce's does, in X-WP-Total).
     *
     * @return array{platform: string, title: string|null, locale: string|null, currency: string|null, products: int|null}|null null when the site does not answer
     */
    public static function read(string $host): ?array
    {
        $home = self::get($host, '/');

        if ($home === null) {
            return null;
        }

        $html = (string) $home->body();
        $shopify = is_array(self::json($host, '/products.json', ['limit' => 1])['products'] ?? null);
        $woo = $shopify ? null : self::get($host, '/wp-json/wc/store/v1/products', ['per_page' => 1]);
        $platform = match (true) {
            $shopify => self::SHOPIFY,
            is_array($woo?->json()) => self::WOOCOMMERCE,
            in_array('wp/v2', (array) (self::json($host, '/wp-json/')['namespaces'] ?? []), true) => self::WORDPRESS,
            default => self::OTHER,
        };
        $total = $platform === self::WOOCOMMERCE ? (int) $woo?->header('X-WP-Total') : 0;
        $storeCurrency = match ($platform) {
            self::WORDPRESS => WordPressSite::read($host)['currency'],
            default => null,
        };

        preg_match('~<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)~i', $html, $site);
        preg_match('~<title[^>]*>(.*?)</title>~is', $html, $title);
        preg_match('~<html[^>]+lang=["\']([a-z]{2})~i', $html, $lang);
        preg_match('~Shopify\.currency\s*=\s*\{"active":"([A-Z]{3})"~', $html, $currency);
        $name = trim(html_entity_decode(strip_tags($site[1] ?? (string) preg_split('/\s[|\-–—]\s/u', (string) ($title[1] ?? ''))[0]), ENT_QUOTES | ENT_HTML5));

        return [
            'platform' => $platform,
            'title' => $name !== '' ? mb_substr($name, 0, 120) : null,
            'locale' => isset($lang[1]) ? strtolower($lang[1]) : null,
            'currency' => $currency[1] ?? (is_string($storeCurrency) && preg_match('/^[A-Z]{3}$/', $storeCurrency) === 1 ? $storeCurrency : null),
            'products' => $total > 0 ? $total : null,
        ];
    }

    /** A page's words, its headings, paragraphs and list items each on a line of its own. */
    public static function text(string $html): string
    {
        $html = (string) preg_replace('~<(script|style|noscript|svg|nav|footer|header|form)\b[^>]*>.*?</\1>~is', ' ', $html);
        $html = (string) preg_replace('~<(br|/p|/h[1-6]|/li|/div|/tr|/section|/article)\b[^>]*>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s*\n\s*/u', "\n", $text));
    }
}
