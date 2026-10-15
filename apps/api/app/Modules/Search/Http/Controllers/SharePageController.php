<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Models\SearchShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /s/{token}[?format=json]
 *
 * A shared search as a page an AI reads well: plain headings, a list per product with its
 * details, sizes and price, the store's policies, and the same as schema.org data; or as JSON.
 * Never indexed by search engines. Expired, it says so.
 */
final class SharePageController
{
    public function __invoke(Request $request, TenantContext $tenant, string $token): Response|JsonResponse
    {
        $share = preg_match('/^[a-z0-9]{8,24}$/', $token) === 1
            ? $tenant->runUnscoped(fn () => SearchShare::query()->where('token', $token)->first())
            : null;
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'public, max-age=300'];

        if ($share === null || $share->expires_at->isPast()) {
            return response()->view('search::share', ['share' => null, 'locale' => 'he'], $share === null ? 404 : 410, $headers);
        }

        $tenant->runUnscoped(fn () => SearchShare::query()->whereKey($share->id)->increment('opens'));
        $snapshot = $share->snapshot;

        if ($request->query('format') === 'json') {
            return response()->json($snapshot, 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $locale = in_array($snapshot['store']['locale'] ?? 'he', ['he', 'en'], true) ? $snapshot['store']['locale'] : 'he';

        // The store's connector, when it offers one, for an AI that can connect for good.
        $connection = $tenant->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $share->shop_id)->first());
        $connector = $connection !== null && Features::enabled('search.agent_connector', $share->shop_id) ? route('api.search.mcp', ['site' => $connection->site_key]) : null;

        // The same search on the store itself, for a person the link was sent to.
        $base = rtrim((string) ($snapshot['store']['url'] ?? ''), '/');
        $storeSearch = $base === '' || ($snapshot['query'] ?? '') === '' ? null
            : (($snapshot['store']['platform'] ?? '') === 'shopify' ? $base.'/search?q='.rawurlencode($snapshot['query']) : $base.'/?s='.rawurlencode($snapshot['query']).'&post_type=product');

        return response()->view('search::share', ['share' => $share, 's' => $snapshot, 'locale' => $locale, 'connector' => $connector, 'storeSearch' => $storeSearch, 'json' => route('search.share', ['token' => $share->token, 'format' => 'json'])], 200, $headers);
    }
}
