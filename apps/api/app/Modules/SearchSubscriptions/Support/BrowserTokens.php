<?php

namespace App\Modules\SearchSubscriptions\Support;

/**
 * The token a browser keeps for a search it subscribed to: the subscription's id and a keyed signature. It says
 * whether that one search is confirmed and stops it, and tells nothing of the address.
 */
final class BrowserTokens
{
    public static function make(string $subscriptionId): string
    {
        return $subscriptionId.'.'.substr(hash_hmac('sha256', 'search-subscription|'.$subscriptionId, (string) config('app.key')), 0, 32);
    }

    /** The subscription's id, or null when the token is not one this app made. */
    public static function read(string $token): ?string
    {
        if (! preg_match('/^([0-9a-z]{26})\.([0-9a-f]{32})$/i', $token, $m)) {
            return null;
        }

        return hash_equals(self::make($m[1]), $token) ? $m[1] : null;
    }
}
