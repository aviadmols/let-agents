<?php

namespace App\Modules\Widget\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Contracts\ProductsForQuery;
use App\Modules\Widget\Support\ProductCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/widget/{site}/searched   {"q": "חולצה לבנה", "id": "<the product they are on>"}
 *
 * "More like what you searched for": a shopper searched "חולצה לבנה" and opened one of the white
 * shirts; the product page offers the others. Only when the product they are on is among what that
 * search finds, so the search is what brought them here, not something they looked for earlier.
 *
 * The search is the store's own, by spelling only: no vector, nothing counted, no model, as a page
 * load requires. The words come from this browser (the search box keeps the shopper's last
 * searches there), in the body, never the URL. One shopper's own, so never part of the cached bank.
 */
final class SearchedController
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private const MAX_QUERY = 120;

    public function __invoke(Request $request, ProductsForQuery $search, TenantContext $tenant, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin === null || ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        $shopId = $connection->shop_id;

        if (! Features::enabled('widget.searched_products', $shopId)) {
            return response()->json(['error' => 'not_enabled'], 403);
        }

        $body = json_decode((string) $request->getContent(), true);
        $query = is_array($body) ? trim((string) ($body['q'] ?? '')) : '';
        $current = is_array($body) ? (string) ($body['id'] ?? '') : '';

        if ($query === '' || mb_strlen($query) > self::MAX_QUERY || ! preg_match(self::ID_PATTERN, $current)) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        $limit = (int) Settings::get('widget.max_products', $shopId);
        $products = $tenant->run($shopId, function () use ($search, $shopId, $query, $current, $limit): array {
            // A few more than shown: the page itself is among them, and some may be gone by the time they render.
            $ids = $search->find($shopId, $query, $limit * 3);

            if (! in_array($current, $ids, true)) {
                return [];
            }

            $others = array_slice(array_values(array_diff($ids, [$current])), 0, $limit);
            $rows = CatalogProduct::query()->active()->whereIn('external_id', $others)->get()
                ->sortBy(fn (CatalogProduct $p): int => (int) array_search($p->external_id, $others, true))->values();

            return ProductCard::many($rows);
        });

        return response()->json(['data' => ['query' => $query, 'products' => $products]])->header('Cache-Control', 'no-store, private');
    }
}
