<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Http\Visit;
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

        // While a shopper is still typing (typing=1): spelling only, so no vector is made per
        // keystroke, and nothing is counted until they stop.
        $typing = $request->boolean('typing');
        // The store's own search page opening (spelling=1): counted, but spelling only, as no page
        // load may reach a model.
        $spelling = $typing || $request->boolean('spelling');
        // A pause in the typing (typing=1&meaning=1, sent by the drawer half a second after the last key):
        // by meaning too, so "בטריה" finds what the store calls "סוללה". One vector per wording (QueryVectors
        // keeps it), still not counted. Never on a page opening.
        $meaning = ! $spelling || ($typing && $request->boolean('meaning'));
        $visit = Visit::from($request, ['sid' => $request->query('sid'), 'page' => $request->query('page'), 'ref' => $request->query('ref')]);
        $result = $search->handle($connection->shop_id, $query, count: ! $typing && ! $request->boolean('counted'), meaning: $meaning, visit: $visit, readWords: true);

        return response()->json($result, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
