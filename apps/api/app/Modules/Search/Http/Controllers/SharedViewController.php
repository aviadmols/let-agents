<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Http\StorefrontSite;
use App\Modules\Search\Models\SearchShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search/{site}/shared/{token}
 *
 * A shared link, opened on the store (?la_s=): what the drawer showed when it was shared (ShareSearch's snapshot), so it
 * opens as it was, never as a new search for the same words (Aviad, 8.11): the words, the products in their order, and
 * the view (an answer with its picks and sources, a photo's results, the like of a product). Only this store's links,
 * until they end. No model, nothing searched again.
 */
final class SharedViewController
{
    public function __invoke(Request $request, string $site, string $token): JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        if (preg_match('/^[a-z0-9]{8,16}$/', $token) !== 1) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $share = app(TenantContext::class)->run((string) $connection->shop_id, fn (): ?SearchShare => SearchShare::query()
            ->where('token', $token)->where('expires_at', '>', now())->first());

        if ($share === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $snapshot = (array) $share->snapshot;

        return response()->json([
            'q' => (string) $share->query,
            // links made before the drawer kept its own list: the products the snapshot holds, in their order
            'ids' => array_values(array_map('strval', (array) ($snapshot['ids'] ?? array_column((array) ($snapshot['products'] ?? []), 'id')))),
            'view' => (array) ($snapshot['view'] ?? ['kind' => 'results']),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
