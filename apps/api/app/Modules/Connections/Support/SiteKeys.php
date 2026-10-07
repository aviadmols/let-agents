<?php

namespace App\Modules\Connections\Support;

/**
 * Keys derived from the plugin's access token, computed the same way in the Let Agents plugin
 * (LetAgents\Storefront\SiteKeys). The plugin stores only the token's SHA-256; the server holds the
 * token itself. Both reach the same values without any extra secret or setting.
 *
 *   site key      public: in every storefront page, identifies the store to the widget API
 *   preview key   private: in preview links, shows the widget to the store team only
 *   signature     private: signs requests from the plugin (orders, reports)
 *
 * None of them reveals the token or its hash. Replacing the token replaces all three.
 */
final class SiteKeys
{
    /** Signed requests older or newer than this are refused. */
    public const SIGNATURE_WINDOW_SECONDS = 300;

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function site(string $token): string
    {
        return substr(hash('sha256', 'let-agents-site|'.self::tokenHash($token)), 0, 24);
    }

    public static function preview(string $token): string
    {
        return substr(hash_hmac('sha256', 'let-agents-preview', self::tokenHash($token)), 0, 32);
    }

    public static function signature(string $token, string $timestamp, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', $timestamp."\n".strtoupper($method)."\n".$path."\n".$body, self::tokenHash($token));
    }

    public static function verify(string $token, string $timestamp, string $method, string $path, string $body, string $signature, ?int $now = null): bool
    {
        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::SIGNATURE_WINDOW_SECONDS) {
            return false;
        }

        return hash_equals(self::signature($token, $timestamp, $method, $path, $body), $signature);
    }
}
