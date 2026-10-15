<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Actions\CalculateCart;
use App\Modules\Search\Http\StorefrontSite;
use App\Modules\Search\Support\OrderItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search/{site}/order?items=31313:1,7677/42928:2,14283@5.4:4
 *
 * The order a shopper's AI wrote, checked line by line (CalculateCart), for our script on the
 * store's own page (?la_order=…) to show and put in the store's cart there: the shopper never
 * leaves the store. Prices and stock from the catalogue; nothing is bought or written here.
 */
final class SearchOrderController
{
    public function __invoke(Request $request, TenantContext $tenant, CalculateCart $calculate, string $site): JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        if (! Features::enabled('search.storefront', $connection->shop_id)) {
            return response()->json(['error' => 'storefront_off'], 404);
        }

        $items = OrderItems::parse(mb_substr((string) $request->query('items', ''), 0, 2000));

        if ($items === []) {
            return response()->json(['error' => 'no_items'], 422);
        }

        $order = $tenant->run((string) $connection->shop_id, fn (): array => $calculate->handle($connection, OrderItems::named($items)));

        return response()->json($order, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
