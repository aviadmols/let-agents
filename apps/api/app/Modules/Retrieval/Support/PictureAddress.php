<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Settings;

/**
 * A picture's address as it is fetched to be read: from Shopify's CDN already resized
 * (retrieval.image_fetch_width wide), never the original upload. A picture is compared at a few
 * hundred pixels, so nothing is lost, every scan downloads less, and a 5 MB PNG (Rocks and Gold's
 * ring 70726) is no longer "too big" to read. Any other address is fetched as it is.
 */
final class PictureAddress
{
    public static function fetchable(string $url): string
    {
        $width = (int) Settings::get('retrieval.image_fetch_width');
        $parts = parse_url($url);

        if ($width <= 0 || $parts === false || ! isset($parts['host'], $parts['path'])) {
            return $url;
        }

        $shopify = strtolower($parts['host']) === 'cdn.shopify.com' || str_starts_with($parts['path'], '/cdn/shop/');

        if (! $shopify) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);
        // A width the address already asks for is the store's own choice: kept.
        if (isset($query['width'])) {
            return $url;
        }

        $query['width'] = (string) $width;

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$parts['path'].'?'.http_build_query($query);
    }
}
