<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * A store's site that struggles is left alone: when it answers with a server error (5xx), "too many
 * requests" (429), or not at all, TIMES in a row, what reads its pages (its product pages, its
 * sitemap's pages) stops and sends it nothing for MINUTES. A missing page (404) is no sign of
 * trouble. Our reading is never what tips a weak server over.
 */
final class StoreRest
{
    public const TIMES = 3;

    public const MINUTES = 30;

    public static function resting(string $shopId): bool
    {
        return Cache::has(self::key($shopId));
    }

    public static function rest(string $shopId): void
    {
        Cache::put(self::key($shopId), now()->toIso8601String(), now()->addMinutes(self::MINUTES));
    }

    /** No answer, a server error or "too many requests": the store struggles (not a page that is missing). */
    public static function struggling(?Response $response): bool
    {
        return $response === null || $response->serverError() || $response->status() === 429;
    }

    private static function key(string $shopId): string
    {
        return 'catalog:store-resting:'.$shopId;
    }
}
