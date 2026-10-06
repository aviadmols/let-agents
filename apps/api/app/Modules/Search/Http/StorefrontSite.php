<?php

namespace App\Modules\Search\Http;

use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shop behind a public site key, for calls the storefront makes from a shopper's browser.
 * A browser on another site is refused; a call without an Origin (the plugin's own server, a
 * same-origin GET) is not, because the data is public anyway.
 */
final class StorefrontSite
{
    public static function resolve(Request $request, string $site): StoreConnection|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin !== null && ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        return $connection;
    }
}
