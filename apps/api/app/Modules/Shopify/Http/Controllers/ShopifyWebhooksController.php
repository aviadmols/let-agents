<?php

namespace App\Modules\Shopify\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Actions\ManageSubscription;
use App\Modules\Shopify\Events\ShopperDataRequested;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifySignatures;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/shopify/webhooks — what Shopify tells the app about a store, signed with the app's
 * secret: the app was removed, its subscription changed, or a privacy request (the three topics
 * every Shopify app must answer).
 */
final class ShopifyWebhooksController
{
    public function __invoke(Request $request, ManageSubscription $billing, TenantContext $tenant): JsonResponse
    {
        $body = (string) $request->getContent();

        if (! ShopifySignatures::webhook($body, $request->header('X-Shopify-Hmac-Sha256'))) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $shop = ShopifySignatures::shopDomain($request->header('X-Shopify-Shop-Domain'));
        $payload = (array) json_decode($body, true);
        $install = $shop === null ? null : ShopifyInstall::query()->where('shop_domain', $shop)->first();

        if ($install === null) {
            return response()->json(['ok' => true]); // nothing kept for this store
        }

        match ($topic) {
            'app/uninstalled' => $this->uninstalled($install, $billing, $tenant),
            'app_subscriptions/update' => $billing->reported(
                $install,
                (string) ($payload['app_subscription']['admin_graphql_api_id'] ?? ''),
                (string) ($payload['app_subscription']['status'] ?? ''),
            ),
            'customers/data_request', 'customers/redact' => event(new ShopperDataRequested(
                (string) $install->shop_id,
                $topic === 'customers/redact' ? 'redact' : 'report',
                mb_strtolower((string) ($payload['customer']['email'] ?? '')),
            )),
            // 48 hours after the app was removed: the shop and all it holds go.
            'shop/redact' => Shop::query()->whereKey($install->shop_id)->delete(),
            default => null,
        };

        return response()->json(['ok' => true]);
    }

    /** The store removed the app: no more access, the shop is paused and kept until shop/redact. */
    private function uninstalled(ShopifyInstall $install, ManageSubscription $billing, TenantContext $tenant): void
    {
        $install->forceFill([
            'uninstalled_at' => now(),
            'access_token' => null,
            'refresh_token' => null,
            'subscription_status' => 'cancelled',
        ])->save();

        $tenant->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $install->shop_id)->update(['status' => ConnectionStatus::Failed]));
        $billing->follow($install);
    }
}
