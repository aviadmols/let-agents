<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Actions\SearchForAgent;
use App\Modules\Search\Support\AgentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * GET /c/{site}[?q=words][&page=n][&format=json]
 *
 * The whole store for a shopper's own AI, as one public page: at the top, the instructions (how
 * to search any product here, and to end every answer with the link that buys it); then the
 * products, searched or all of them, a page at a time, each with its details, options and buy
 * link; then the store's policies. An AI that can open a page needs nothing else. Code only, from
 * the store's own search; the same page is kept a few minutes, so it opens at once.
 */
final class StoreCatalogController
{
    public const PER_PAGE = 30;

    private const KEEP_SECONDS = 300;

    /**
     * @param  array<string, mixed>  $store
     * @param  array{found: array<string, mixed>, policies: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    private static function forAi(array $store, array $data): array
    {
        $found = $data['found'];
        $next = $found['page'] < $found['pages'] ? ($store['catalog'] ?? '').'?'.http_build_query(array_filter(['q' => $found['query'] ?: null, 'page' => $found['page'] + 1, 'format' => 'json'])) : null;

        return AgentCatalog::forAi($store, $found['products'], (string) $found['as_of'], array_filter([
            'query' => $found['query'] ?: null,
            'read' => array_filter((array) $found['read']) ?: null,
            'total' => $found['total'],
            'page' => $found['page'],
            'pages' => $found['pages'],
            'next_page' => $next,
            'policies' => array_filter($data['policies']) ?: null,
        ], fn ($value): bool => $value !== null));
    }

    public function __invoke(Request $request, TenantContext $tenant, SearchForAgent $search, string $site): Response|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null || ! Features::enabled('search.agent_connector', $connection->shop_id)) {
            abort(404);
        }

        $query = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        $page = max(1, min(500, (int) $request->query('page', 1)));
        $shopId = (string) $connection->shop_id;
        $store = AgentCatalog::store($connection);

        $data = Cache::remember('search:agent-catalog:'.$site.':'.hash('sha256', $query).':'.$page, self::KEEP_SECONDS, fn (): array => $tenant->run($shopId, fn (): array => [
            'found' => $search->handle($shopId, $query, self::PER_PAGE, $page, $store),
            'policies' => $page === 1 ? AgentCatalog::policies() : ['promises' => [], 'pages' => []],
        ]));
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'public, max-age='.self::KEEP_SECONDS];

        if ($request->query('format') === 'json') {
            return response()->json(self::forAi($store, $data), 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        $locale = in_array($store['locale'], ['he', 'en'], true) ? $store['locale'] : 'he';

        return response()->view('search::catalog', [
            'ai' => self::forAi($store, $data),
            'store' => $store,
            'c' => $data['found'],
            'policies' => $data['policies'],
            'locale' => $locale,
            'connector' => AgentCatalog::publicUrl('api/v1/mcp/'.$site),
        ], 200, $headers);
    }
}
