<?php

namespace App\Modules\Shopify\Support;

/**
 * The session token (a JWT) Shopify hands an embedded app when it opens inside the admin, and
 * App Bridge adds to the app's requests: signed with the app's secret (HS256), good for a minute,
 * naming the store (dest) and the app (aud). Proof enough to open the store's panel and, for a
 * store without our token yet, to exchange for one.
 */
final class ShopifySessionToken
{
    /** Clocks differ a little; a token this many seconds past its time still counts. */
    private const LEEWAY_SECONDS = 10;

    /**
     * The token's claims, or null when it is not a token one of our apps signed for a store, or
     * its time has not come or has gone.
     *
     * @return array{app: string, shop: string, user: string|null, exp: int}|null
     */
    public static function claims(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$head, $body, $signature] = $parts;
        $header = json_decode(self::decode($head) ?? '', true);
        $claims = json_decode(self::decode($body) ?? '', true);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $app = (string) ($claims['aud'] ?? '');
        $secret = ShopifyApps::all()[$app] ?? '';

        if ($secret === '' || ! hash_equals(self::encode(hash_hmac('sha256', $head.'.'.$body, $secret, true)), $signature)) {
            return null;
        }

        $now = time();

        if ((int) ($claims['exp'] ?? 0) < $now - self::LEEWAY_SECONDS || (int) ($claims['nbf'] ?? 0) > $now + self::LEEWAY_SECONDS) {
            return null;
        }

        $shop = ShopifySignatures::shopDomain((string) parse_url((string) ($claims['dest'] ?? ''), PHP_URL_HOST));
        $issuer = strtolower((string) parse_url((string) ($claims['iss'] ?? ''), PHP_URL_HOST));

        if ($shop === null || $issuer !== $shop) {
            return null;
        }

        return [
            'app' => $app,
            'shop' => $shop,
            'user' => isset($claims['sub']) ? (string) $claims['sub'] : null,
            'exp' => (int) $claims['exp'],
        ];
    }

    private static function decode(string $part): ?string
    {
        $padded = str_pad(strtr($part, '-_', '+/'), (int) (ceil(strlen($part) / 4) * 4), '=');
        $bytes = base64_decode($padded, true);

        return $bytes === false ? null : $bytes;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
