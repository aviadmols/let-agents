<?php

namespace App\Core\Http;

use Illuminate\Http\Request;

/**
 * Who did something on a store's page, as the store manager reads it next to a search, a photo
 * search or a wishlist: the address (the shopper's own, behind Railway's trusted proxy), the
 * device, browser and system from the browser's own header, and what the storefront script sends
 * of the visit: its session, the page, and where the shopper came from.
 *
 * Kept for a bounded time (each module's visit_keep_days), then the address and the session are
 * cleared and what happened stays.
 *
 * @phpstan-type VisitDetails array{ip: string|null, device: string|null, browser: string|null, os: string|null, session_id: string|null, page: string|null, referrer: string|null}
 */
final class Visit
{
    /** The columns a record keeps of a visit, in this order. */
    public const COLUMNS = ['ip', 'device', 'browser', 'os', 'session_id', 'page', 'referrer'];

    /**
     * @param  array<string, mixed>  $sent  what the script sent: sid, page, ref
     * @return array<string, string|null>
     */
    public static function from(Request $request, array $sent = []): array
    {
        $agent = (string) $request->userAgent();
        $session = is_string($sent['sid'] ?? null) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $sent['sid']) === 1 ? $sent['sid'] : null;
        $page = is_string($sent['page'] ?? null) ? mb_substr(self::path($sent['page']), 0, 300) : null;
        $referrer = is_string($sent['ref'] ?? null) ? self::host($sent['ref']) : null;

        return [
            'ip' => $request->ip(),
            'device' => $agent === '' ? null : self::device($agent),
            'browser' => $agent === '' ? null : self::browser($agent),
            'os' => $agent === '' ? null : self::os($agent),
            'session_id' => $session,
            'page' => $page === '' ? null : $page,
            'referrer' => $referrer,
        ];
    }

    public static function device(string $agent): string
    {
        return match (true) {
            preg_match('/iPad|Tablet|SM-T\d/i', $agent) === 1 => 'tablet',
            preg_match('/Mobi|iPhone|Android/i', $agent) === 1 => 'phone',
            default => 'computer',
        };
    }

    public static function browser(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Instagram') => 'Instagram',
            preg_match('/FBAN|FBAV/', $agent) === 1 => 'Facebook',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'CriOS') || str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'other',
        };
    }

    public static function os(string $agent): string
    {
        return match (true) {
            preg_match('/iPhone|iPad|iPod/', $agent) === 1 => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'other',
        };
    }

    /** "/products/70726" of a full address; a path as it is. */
    private static function path(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? rawurldecode($path) : '';
    }

    /** "google.com", "instagram.com": where the shopper came from, never the full address. */
    private static function host(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' ? null : mb_substr((string) preg_replace('/^(www|m|l|lm)\./', '', $host), 0, 120);
    }
}
