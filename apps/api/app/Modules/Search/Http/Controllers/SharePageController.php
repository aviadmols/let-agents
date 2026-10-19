<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Models\SearchShare;
use App\Modules\Search\Support\AgentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

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
        // Never indexed; its links are followed, so an AI can open every category and page from it.
        $headers = ['X-Robots-Tag' => 'noindex', 'Cache-Control' => 'public, max-age=300'];

        if ($share === null || $share->expires_at->isPast()) {
            return response()->view('search::share', ['share' => null, 'locale' => 'he'], $share === null ? 404 : 410, $headers);
        }

        $tenant->runUnscoped(fn () => SearchShare::query()->whereKey($share->id)->increment('opens'));
        $snapshot = $share->snapshot;

        $locale = in_array($snapshot['store']['locale'] ?? 'he', ['he', 'en'], true) ? $snapshot['store']['locale'] : 'he';
        // Every category and the whole catalogue in short, as links: the AI is not held to what the search found.
        $browse = empty($snapshot['store']['catalog']) ? null : $tenant->run((string) $share->shop_id, fn (): array => AgentCatalog::browse($snapshot['store'], StoreCatalogController::COMPACT_PER_PAGE));

        if ($request->query('format') === 'json') {
            // The whole store in short, inside this JSON as the store's own JSON has it: an AI's browser
            // often opens no second link, so a category's products behind one were never seen ("I could
            // not open the list of the 27 books"). Within the shop's cap (search.ai_catalog_products).
            $shopId = (string) $share->shop_id;
            $everything = empty($snapshot['store']['catalog']) ? null : Cache::remember('search:share-everything:'.$shopId, 300, fn (): array => $tenant->run($shopId,
                fn (): array => AgentCatalog::everything($snapshot['store'], (int) Settings::get('search.ai_catalog_products', $shopId))));

            return response()->json(AgentCatalog::forAi($snapshot['store'], $snapshot['products'], (string) $snapshot['as_of'], array_filter(['browse' => $browse, 'query' => $snapshot['query'], 'read' => $snapshot['read'] ?? null, 'policies' => $snapshot['policies'] ?? null, 'everything' => $everything])), 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        // The same search on the store itself, for a person the link was sent to.
        $base = rtrim((string) ($snapshot['store']['url'] ?? ''), '/');
        $storeSearch = $base === '' || ($snapshot['query'] ?? '') === '' ? null
            : (($snapshot['store']['platform'] ?? '') === 'shopify' ? $base.'/search?q='.rawurlencode($snapshot['query']) : $base.'/?s='.rawurlencode($snapshot['query']).'&post_type=product');

        return response()->view('search::share', ['share' => $share, 's' => $snapshot, 'locale' => $locale, 'storeSearch' => $storeSearch, 'browse' => $browse, 'json' => AgentCatalog::publicUrl('s/'.$share->token.'?format=json')], 200, $headers);
    }
}
