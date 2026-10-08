<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Http\StorefrontSite;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/v1/search/{site}/photo — multipart, field "photo".
 *
 * A shopper's photo, compared with the shop's product pictures. Refused when the shop has search
 * by photo off, the file is not a JPEG, PNG or WEBP picture, it is too big, or the shop reached
 * its photos for the day. The photo is read in memory and never stored.
 */
final class SearchPhotoController
{
    private const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __invoke(Request $request, SearchCatalog $search, string $site): JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;

        if (! Features::enabled('search.storefront', $shopId) || ! Features::enabled('search.photos', $shopId)) {
            return response()->json(['error' => 'photos_off'], 404);
        }

        $file = $request->file('photo');

        if ($file === null || ! $file->isValid()) {
            return response()->json(['error' => 'no_photo'], 422);
        }

        if ($file->getSize() > (int) Settings::get('search.photo_max_kb') * 1024) {
            return response()->json(['error' => 'too_big'], 413);
        }

        // What the bytes are, not what the browser or the file name says they are.
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($mime, self::MIMES, true)) {
            return response()->json(['error' => 'not_image'], 415);
        }

        $key = "search:photos:{$shopId}:".now()->toDateString();
        Cache::add($key, 0, now()->endOfDay());

        if (Features::enabled('search.photo_limits', $shopId) && Cache::increment($key) > (int) Settings::get('search.photos_per_day', $shopId)) {
            return response()->json(['error' => 'daily_limit'], 429);
        }

        $result = $search->photo($shopId, $mime, $bytes);

        return response()->json($result, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
