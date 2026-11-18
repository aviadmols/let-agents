<?php

namespace App\Modules\SearchSubscriptions\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\SearchSubscriptions\Actions\StopSubscriptions;
use App\Modules\SearchSubscriptions\Actions\Subscribe;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Support\BrowserTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The drawer's side of Search Subscriptions. Sent as text/plain from the storefront (no preflight); the body is JSON.
 *
 * - POST /api/v1/search/{site}/subscribe  {"email", "query", "consent": true, "consent_text", "locale"}: the
 *   confirmation email goes out. Answers a browser token, kept in that browser only, that later says whether this
 *   search is confirmed (the black "Subscribed" bar) and stops it from the bar; it reveals no address.
 * - POST /api/v1/search/{site}/subscription  {"token"}: pending, active or stopped.
 * - POST /api/v1/search/{site}/subscription/stop  {"token"}: stopped from the bar.
 */
final class SubscribeController
{
    public function subscribe(Request $request, Subscribe $subscribe, string $site): JsonResponse
    {
        [$connection, $error] = $this->site($request, $site);
        if ($error !== null) {
            return $error;
        }
        $body = $this->body($request);
        $result = $subscribe->handle(
            $connection->shop_id,
            (string) ($body['email'] ?? ''),
            (string) ($body['query'] ?? ''),
            ($body['locale'] ?? 'he') === 'en' ? 'en' : 'he',
            ($body['consent'] ?? false) === true,
            (string) ($body['consent_text'] ?? ''),
            $request->ip() === null ? null : hash('sha256', (string) $request->ip().'|'.config('app.key')),
        );
        $subscription = $result['subscription'] ?? null;

        return response()->json(['status' => $result['status']] + ($subscription === null ? [] : [
            'token' => BrowserTokens::make($subscription->id), 'confirmed' => $subscription->status === SearchSubscription::ACTIVE,
        ]), in_array($result['status'], [Subscribe::SENT, Subscribe::ALREADY], true) ? 200 : 422);
    }

    public function status(Request $request, TenantContext $tenant, string $site): JsonResponse
    {
        [$connection, $error] = $this->site($request, $site);
        if ($error !== null) {
            return $error;
        }
        $subscription = $this->byToken($tenant, $connection->shop_id, (string) ($this->body($request)['token'] ?? ''));

        return response()->json(['status' => $subscription?->status ?? 'none']);
    }

    public function stop(Request $request, TenantContext $tenant, StopSubscriptions $stop, string $site): JsonResponse
    {
        [$connection, $error] = $this->site($request, $site);
        if ($error !== null) {
            return $error;
        }
        $subscription = $this->byToken($tenant, $connection->shop_id, (string) ($this->body($request)['token'] ?? ''));
        if ($subscription !== null) {
            $stop->one($subscription);
        }

        return response()->json(['status' => 'stopped']);
    }

    /** @return array{0: StoreConnection|null, 1: JsonResponse|null} */
    private function site(Request $request, string $site): array
    {
        $connection = StoreConnection::forSite($site);
        if ($connection === null) {
            return [null, response()->json(['error' => 'unknown_site'], 404)];
        }
        $origin = $request->header('Origin');
        if ($origin === null || ! $connection->allowsOrigin($origin)) {
            return [null, response()->json(['error' => 'other_origin'], 403)];
        }
        if (! Features::enabled('search_subscriptions.enabled', $connection->shop_id)) {
            return [null, response()->json(['error' => 'not_enabled'], 403)];
        }

        return [$connection, null];
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function byToken(TenantContext $tenant, string $shopId, string $token): ?SearchSubscription
    {
        $id = BrowserTokens::read($token);

        return $id === null ? null : $tenant->run($shopId, fn () => SearchSubscription::query()->find($id));
    }
}
