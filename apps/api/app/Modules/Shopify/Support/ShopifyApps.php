<?php

namespace App\Modules\Shopify\Support;

/**
 * The Shopify apps this server answers for: the public app (SHOPIFY_API_KEY / SHOPIFY_API_SECRET)
 * and any custom-distribution apps made for single stores before the public one is approved
 * (SHOPIFY_MORE_APPS, "client_id:secret" pairs separated by commas). They all run the same code;
 * Shopify signs every request with the secret of the app it is about, and that tells them apart.
 */
final class ShopifyApps
{
    /** @return array<string, string> client id => secret, the public app first */
    public static function all(): array
    {
        $apps = [];
        $key = (string) config('services.shopify.key');

        if ($key !== '' && (string) config('services.shopify.secret') !== '') {
            $apps[$key] = (string) config('services.shopify.secret');
        }

        foreach (explode(',', (string) config('services.shopify.more_apps')) as $pair) {
            [$id, $secret] = array_pad(explode(':', trim($pair), 2), 2, '');

            if ($id !== '' && $secret !== '') {
                $apps[$id] ??= $secret;
            }
        }

        return $apps;
    }

    /** The app's client id, or the public app's when none (or an unknown one) is given. */
    public static function key(?string $clientId = null): string
    {
        $apps = self::all();

        return $clientId !== null && isset($apps[$clientId]) ? $clientId : (string) array_key_first($apps);
    }

    public static function secret(?string $clientId = null): string
    {
        return self::all()[self::key($clientId)] ?? '';
    }
}
