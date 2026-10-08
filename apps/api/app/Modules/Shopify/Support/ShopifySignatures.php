<?php

namespace App\Modules\Shopify\Support;

/**
 * Proof that a request comes from Shopify: the install and admin links (query hmac), webhooks
 * (body hmac in a header) and the app proxy (query signature). Each is signed with the secret of
 * one of our apps (ShopifyApps); the checks say which, and compare in constant time.
 */
final class ShopifySignatures
{
    /** A myshopify domain, the only kind of shop name the app accepts. */
    public static function shopDomain(?string $shop): ?string
    {
        $shop = strtolower(trim((string) $shop));

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) === 1 ? $shop : null;
    }

    /** @param array<string, mixed> $query */
    public static function query(array $query): bool
    {
        return self::queryApp($query) !== null;
    }

    /**
     * The client id of the app that signed these query parameters, or null.
     *
     * @param  array<string, mixed>  $query
     */
    public static function queryApp(array $query): ?string
    {
        $hmac = (string) ($query['hmac'] ?? '');
        unset($query['hmac'], $query['signature']);
        ksort($query);

        $message = implode('&', array_map(
            fn (string $key, mixed $value): string => $key.'='.(is_array($value) ? '["'.implode('", "', $value).'"]' : (string) $value),
            array_keys($query),
            $query,
        ));

        return $hmac === '' ? null : self::signer(fn (string $secret): bool => hash_equals(hash_hmac('sha256', $message, $secret), $hmac));
    }

    public static function webhook(string $body, ?string $header): bool
    {
        return $header !== null
            && self::signer(fn (string $secret): bool => hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $header)) !== null;
    }

    /** @param array<string, mixed> $query */
    public static function proxy(array $query): bool
    {
        $signature = (string) ($query['signature'] ?? '');
        unset($query['signature']);
        ksort($query);

        $message = implode('', array_map(
            fn (string $key, mixed $value): string => $key.'='.(is_array($value) ? implode(',', $value) : (string) $value),
            array_keys($query),
            $query,
        ));

        return $signature !== '' && self::signer(fn (string $secret): bool => hash_equals(hash_hmac('sha256', $message, $secret), $signature)) !== null;
    }

    /** @param callable(string): bool $matches */
    private static function signer(callable $matches): ?string
    {
        foreach (ShopifyApps::all() as $clientId => $secret) {
            if ($matches($secret)) {
                return (string) $clientId;
            }
        }

        return null;
    }
}
