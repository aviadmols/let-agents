<?php

namespace App\Modules\Widget\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Where a page's bank waits between requests (PageBankController), and how a shop forgets all of its
 * pages at once. A cache without tags cannot list a shop's keys, so every key carries the shop's
 * generation: a settings page that changes what the widget shows starts a new generation, and the old
 * pages are never read again (they expire on their own within widget.page_cache_seconds).
 */
final class PageCache
{
    public static function key(string $shopId, string $type, string $id, string $locale): string
    {
        return "widget:page:{$shopId}:".self::generation($shopId).":{$type}:{$id}:{$locale}";
    }

    /** One page, after the team changed what it shows (the per-page editor). */
    public static function forget(string $shopId, string $type, string $id, string $locale): void
    {
        Cache::forget(self::key($shopId, $type, $id, $locale));
    }

    /** Every page of the shop: shoppers get pages built with the new settings on their next request. */
    public static function forgetShop(string $shopId): void
    {
        Cache::forever(self::generationKey($shopId), Str::lower(Str::random(8)));
    }

    private static function generation(string $shopId): string
    {
        return (string) Cache::get(self::generationKey($shopId), '0');
    }

    private static function generationKey(string $shopId): string
    {
        return "widget:page:{$shopId}:generation";
    }
}
