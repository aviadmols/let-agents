<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Http\StorefrontSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search/{site}?q=…[&counted=1]
 *
 * The full results for a query, by spelling and by meaning, grouped. `counted=1` when the
 * storefront already counted this search in this tab (the shopper paused over the suggestions
 * before pressing Enter), so it is not counted twice.
 */
final class SearchController
{
    public function __invoke(Request $request, SearchCatalog $search, string $site): JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        if (! Features::enabled('search.storefront', $connection->shop_id)) {
            return response()->json(['error' => 'search_off'], 404);
        }

        $query = trim((string) $request->query('q', ''));

        if ($query === '' || mb_strlen($query) > 120) {
            return response()->json(['error' => 'invalid_query'], 422);
        }

        $result = $search->handle($connection->shop_id, $query, count: ! $request->boolean('counted'));

        return response()->json($result, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
