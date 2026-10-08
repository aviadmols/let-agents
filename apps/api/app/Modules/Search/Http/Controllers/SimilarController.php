<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Http\StorefrontSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search/{site}/similar?id=<product id>
 *
 * Products like one in the results, for its "similar items" button. From stored vectors only:
 * no model is asked, so the same answer is cached for a few minutes.
 */
final class SimilarController
{
    public function __invoke(Request $request, SearchCatalog $search, string $site): JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;

        if (! Features::enabled('search.storefront', $shopId) || ! Features::enabled('search.similar', $shopId)) {
            return response()->json(['error' => 'similar_off'], 404);
        }

        $id = (string) $request->query('id', '');

        if (! preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id)) {
            return response()->json(['error' => 'invalid_product'], 422);
        }

        $products = $search->similar($shopId, $id, (int) Settings::get('search.results_per_group', $shopId));

        return response()->json(['products' => $products], 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
