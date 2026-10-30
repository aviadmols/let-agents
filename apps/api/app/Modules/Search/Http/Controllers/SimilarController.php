<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Http\StorefrontSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search/{site}/similar?id=<product id>[&limit=1..24][&for=oos][&colour=other][&q=<the question asked>]
 *
 * Products like one in the results, for its "similar items" button. From stored vectors only:
 * no model is asked, so the same answer is cached for a few minutes.
 */
final class SimilarController
{
    public const MAX_LIMIT = 24;

    private const MAX_ASKED = 200;

    /** Below this many alike, the others of its kind are offered apart. */
    public const FEW_ALIKE = 4;

    public function __invoke(Request $request, SearchCatalog $search, string $site): JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;

        // The "similar items" button, or (for=oos) the product page's row for a product or a size sold out.
        $wanted = $request->query('for') === 'oos' ? 'search.out_of_stock_similar' : 'search.similar';

        if (! Features::enabled('search.storefront', $shopId) || ! Features::enabled($wanted, $shopId)) {
            return response()->json(['error' => 'similar_off'], 404);
        }

        $id = (string) $request->query('id', '');

        if (! preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $id)) {
            return response()->json(['error' => 'invalid_product'], 422);
        }

        // A bigger pool when the page will keep only some (the ones in a size that is gone here).
        // Else as many as the shop chose for its similar items (search.similar_count), or, where it chose none, as a group of results.
        $limit = $request->has('limit') ? max(1, min(self::MAX_LIMIT, (int) $request->query('limit')))
            : ((int) Settings::get('search.similar_count', $shopId) ?: (int) Settings::get('search.results_per_group', $shopId));
        // "colour=other": its alike in another colour or stone (a question asked on its page, "יש דומות בצבע אחר?")
        // "q": the question asked on its page, for what else it wants of them ("עם יהלום והרבה יותר יקרה"), read in code
        $asked = mb_substr(trim((string) $request->query('q', '')), 0, self::MAX_ASKED);
        $wants = $asked === '' ? null : $search->similarWants($shopId, $id, $asked);
        $products = $search->similar($shopId, $id, $limit, $request->query('colour') === 'other', $wants, $others);
        // few or none look like it: those of its kind its look left out, to be shown apart as "maybe these" (never as alike)
        $others = count($products) < self::FEW_ALIKE ? array_slice((array) $others, 0, $limit) : [];

        return response()->json(['products' => $products] + ($others === [] ? [] : ['others' => $others]) + ($wants === null ? [] : ['asked' => $wants]), 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
