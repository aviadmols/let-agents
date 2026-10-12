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
 *   customer      private: the plugin's word, in a page, for which store account is signed in
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

    /** How long a page's word for its signed-in customer holds: a page may stay open for a day or two. */
    public const CUSTOMER_WINDOW_SECONDS = 172800;

    /** The plugin's word for a signed-in store account, printed in the page: "<user id>.<time>.<hmac>". */
    public static function customer(string $token, string $userId, string $timestamp): string
    {
        return hash_hmac('sha256', 'let-agents-customer|'.$userId.'|'.$timestamp, self::tokenHash($token));
    }

    /** The store account a page's word names, or null when it is not the plugin's or too old. */
    public static function verifyCustomer(string $token, string $proof, ?int $now = null): ?string
    {
        if (preg_match('/^([0-9]{1,20})\.([0-9]{9,11})\.([0-9a-f]{64})$/', $proof, $m) !== 1 || abs(($now ?? time()) - (int) $m[2]) > self::CUSTOMER_WINDOW_SECONDS) {
            return null;
        }

        return hash_equals(self::customer($token, $m[1], $m[2]), $m[3]) ? $m[1] : null;
    }
}
