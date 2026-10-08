<?php

namespace App\Modules\Shopify\Support;

/**
 * Proof that a request comes from Shopify: the install and admin links (query hmac), webhooks
 * (body hmac in a header) and the app proxy (query signature). All with the app's secret, all
 * compared in constant time.
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
        $hmac = (string) ($query['hmac'] ?? '');
        unset($query['hmac'], $query['signature']);
        ksort($query);

        $message = implode('&', array_map(
            fn (string $key, mixed $value): string => $key.'='.(is_array($value) ? '["'.implode('", "', $value).'"]' : (string) $value),
            array_keys($query),
            $query,
        ));

        return $hmac !== '' && self::secret() !== '' && hash_equals(hash_hmac('sha256', $message, self::secret()), $hmac);
    }

    public static function webhook(string $body, ?string $header): bool
    {
        return $header !== null && self::secret() !== ''
            && hash_equals(base64_encode(hash_hmac('sha256', $body, self::secret(), true)), $header);
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

        return $signature !== '' && self::secret() !== '' && hash_equals(hash_hmac('sha256', $message, self::secret()), $signature);
    }

    private static function secret(): string
    {
        return (string) config('services.shopify.secret');
    }
}
