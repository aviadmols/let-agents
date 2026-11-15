<?php

namespace App\Modules\Shopify\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Actions\ManageSubscription;
use App\Modules\Shopify\Jobs\HandleComplianceWebhook;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifySignatures;
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

        $signer = ShopifySignatures::webhookSigner($body, $request->header('X-Shopify-Hmac-Sha256'));

        if ($signer === null) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $shop = ShopifySignatures::shopDomain($request->header('X-Shopify-Shop-Domain'));
        $payload = (array) json_decode($body, true);

        // The store is named in a header the signature does not cover: a signed body is taken for its own store only
        // (the store it names, when it names one), and only from the app that store installed (1.11: a body signed for
        // one store, sent again naming another, could have removed that one).
        $named = $payload['shop_domain'] ?? $payload['myshopify_domain'] ?? null;

        if (is_string($named) && $named !== '' && ShopifySignatures::shopDomain($named) !== $shop) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        $install = $shop === null ? null : ShopifyInstall::query()->where('shop_domain', $shop)->first();

        if ($install === null) {
            return response()->json(['ok' => true]); // nothing kept for this store
        }

        if (filled($install->client_id) && (string) $install->client_id !== $signer) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        match ($topic) {
            'app/uninstalled' => $this->uninstalled($install, $billing, $tenant),
            'app_subscriptions/update' => $billing->reported(
                $install,
                (string) ($payload['app_subscription']['admin_graphql_api_id'] ?? ''),
                (string) ($payload['app_subscription']['status'] ?? ''),
            ),
            // the privacy topics: answered 200 at once, the work in the background (HandleComplianceWebhook): a shop/redact
            // deleting a whole shop took 6,009 ms and timed out on Shopify's first try (14.11)
            'customers/data_request', 'customers/redact', 'shop/redact' => HandleComplianceWebhook::dispatch(
                $topic,
                (string) $install->shop_id,
                [
                    'email' => (string) ($payload['customer']['email'] ?? ''),
                    'id' => (string) ($payload['customer']['id'] ?? ''),
                    'phone' => (string) ($payload['customer']['phone'] ?? ''),
                ],
                (string) ($payload['data_request']['id'] ?? ''),
            ),
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
