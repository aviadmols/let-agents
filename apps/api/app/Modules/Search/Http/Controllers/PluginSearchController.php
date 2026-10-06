<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Actions\SearchCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/plugin/{site}/search?q=… — signed by the store's plugin.
 *
 * The store's own search results page asks this for the order to show: product and post IDs
 * as the store knows them, best first, so the page lists what the search box suggested, in the
 * same order. The plugin falls back to the store's own search when this does not answer.
 */
final class PluginSearchController
{
    private const LIMIT = 200;

    public function __invoke(Request $request, SearchCatalog $search, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $valid = $connection->verifyPluginSignature(
            (string) $request->header('X-Rega-Timestamp'),
            $request->method(),
            $request->getRequestUri(),
            (string) $request->getContent(),
            (string) $request->header('X-Rega-Signature'),
        );

        if (! $valid) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        $query = trim((string) $request->query('q', ''));

        if (! Features::enabled('search.storefront', $connection->shop_id) || $query === '' || mb_strlen($query) > 120) {
            return response()->json(['products' => [], 'content' => [], 'searched' => false]);
        }

        $result = $search->handle($connection->shop_id, $query, ['product', 'content'], count: ! $request->boolean('counted'), perGroup: self::LIMIT);

        return response()->json([
            'searched' => true,
            'products' => array_column($result['groups']['product'], 'external_id'),
            'content' => array_column($result['groups']['content'], 'external_id'),
        ])->header('Cache-Control', 'no-store');
    }
}
