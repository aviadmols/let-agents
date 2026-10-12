<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Actions\RecordOrder;
use App\Modules\Analytics\Models\AnalyticsOrder;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/pixel/{site}/orders — a Shopify order, from the app's web pixel at checkout_completed
 * (extensions/shopify/extensions/let-agents-pixel). Shopify sends us no orders otherwise.
 *
 * The pixel runs in the shopper's browser, in Shopify's sandbox, so the request is not signed and
 * its origin is Shopify's: it is reported, not verified. What keeps it honest: the site key, a
 * Shopify store, products the store really sells, one order per order number, the rate limit, and
 * the order marked as the pixel's. It carries no customer data: the order number only as a hash,
 * totals, product ids and quantities, and the anonymous visitor id the storefront scripts keep.
 *
 * Sent as text/plain, so no preflight.
 */
final class PixelOrdersController
{
    private const MAX_BYTES = 32_000;

    public function __invoke(Request $request, RecordOrder $record, TenantContext $tenant, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null || $connection->platform !== 'shopify') {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        if (! Features::enabled('analytics.collect', $connection->shop_id)) {
            return response()->json(['stored' => false], 202);
        }

        $raw = (string) $request->getContent();
        $payload = strlen($raw) > self::MAX_BYTES ? null : json_decode($raw, true);
        $orderId = is_array($payload) ? (string) ($payload['order_id'] ?? '') : '';

        // A number, or Shopify's gid://shopify/Order/123: the number is the order.
        $orderId = (string) preg_replace('~^gid://shopify/Order/~', '', $orderId);

        if (! preg_match('/^\d{1,20}$/', $orderId) || ! is_array($payload['items'] ?? null)) {
            return response()->json(['error' => 'invalid'], 422);
        }

        $items = array_values(array_filter(array_map(fn ($item): ?array => is_array($item) ? [
            'product_id' => (string) preg_replace('~^gid://shopify/Product/~', '', (string) ($item['product_id'] ?? '')),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'total' => is_numeric($item['total'] ?? null) ? (float) $item['total'] : 0.0,
        ] : null, array_slice($payload['items'], 0, 200))));

        // Only products the store sells: an order of nothing it has was not placed there.
        $known = $tenant->run($connection->shop_id, fn (): array => CatalogProduct::query()
            ->whereIn('external_id', array_column($items, 'product_id'))->pluck('external_id')->map(fn ($id): string => (string) $id)->all());

        if ($known === []) {
            return response()->json(['error' => 'unknown_products'], 422);
        }

        $result = $record->handle($connection, [
            'order_ref' => hash_hmac('sha256', $connection->shop_id.'|shopify-order|'.$orderId, (string) config('app.key')),
            'total' => is_numeric($payload['total'] ?? null) ? (float) $payload['total'] : array_sum(array_column($items, 'total')),
            'currency' => strtoupper((string) ($payload['currency'] ?? '')),
            'ordered_at' => now()->timestamp,
            // A visitor id the scripts did not make is no visitor: the order is kept, unattributed.
            'vid' => is_string($payload['vid'] ?? null) && preg_match('/^anon-[A-Za-z0-9_-]{16,64}$/', $payload['vid']) === 1 ? $payload['vid'] : null,
            'items' => $items,
        ], reportedBy: AnalyticsOrder::REPORTED_BY_PIXEL);

        return $result['problems'] === []
            ? response()->json(['stored' => $result['stored']], 202)
            : response()->json(['error' => 'invalid', 'problems' => $result['problems']], 422);
    }
}
