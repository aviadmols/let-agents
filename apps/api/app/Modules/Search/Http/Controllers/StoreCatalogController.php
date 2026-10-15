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
 * GET /c/{site}[?q=words][&cat=category][&compact=1][&page=n][&format=json]
 *
 * The whole store for a shopper's own AI, as one public page: at the top, the instructions (how
 * to find any product here, and to end every answer with the link that buys it); then the
 * products, searched, of one category, or all of them, a page at a time, each with its details,
 * options and buy link; then the store's policies. An AI's browser opens only addresses it has
 * seen, so every address it needs is written out in full: each category's, each page of the whole
 * catalogue in short (compact: about 200 products a page). Code only, from the store's own search;
 * the same page is kept a few minutes, so it opens at once.
 */
final class StoreCatalogController
{
    public const PER_PAGE = 30;

    public const COMPACT_PER_PAGE = 200;

    private const KEEP_SECONDS = 300;

    /**
     * @param  array<string, mixed>  $store
     * @param  array{found: array<string, mixed>, policies: array<string, mixed>, browse: array<string, mixed>|null}  $data
     * @param  array{q: string, cat: string, compact: bool}  $asked
     * @return array<string, mixed>
     */
    private static function forAi(array $store, array $data, array $asked): array
    {
        $found = $data['found'];
        $next = $found['page'] < $found['pages'] ? AgentCatalog::catalogUrl($store, ['q' => $asked['q'], 'cat' => $asked['cat'], 'compact' => $asked['compact'], 'page' => $found['page'] + 1]) : null;
        $shape = $asked['compact'] ? fn (array $answer): array => AgentCatalog::short($answer) : fn (array $answer): array => $answer;

        return $shape(AgentCatalog::forAi($store, $found['products'], (string) $found['as_of'], array_filter([
            'browse' => $data['browse'],
            'query' => $found['query'] ?: null,
            'category' => $asked['cat'] ?: null,
            'read' => array_filter((array) $found['read']) ?: null,
            'total' => $found['total'],
            'page' => $found['page'],
            'pages' => $found['pages'],
            'next_page' => $next,
            'policies' => array_filter($data['policies']) ?: null,
            'compact' => $asked['compact'] ?: null,
        ], fn ($value): bool => $value !== null)));
    }

    public function __invoke(Request $request, TenantContext $tenant, SearchForAgent $search, string $site): Response|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null || ! Features::enabled('search.agent_connector', $connection->shop_id)) {
            abort(404);
        }

        $asked = [
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 200),
            'cat' => preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) $request->query('cat', '')) === 1 ? (string) $request->query('cat') : '',
            'compact' => $request->boolean('compact'),
        ];
        $page = max(1, min(500, (int) $request->query('page', 1)));
        $shopId = (string) $connection->shop_id;
        $store = AgentCatalog::store($connection);
        // The front page: where everything is, written out in full.
        $front = $page === 1 && $asked['q'] === '' && $asked['cat'] === '' && ! $asked['compact'];

        $data = Cache::remember('search:agent-catalog:'.$site.':'.hash('sha256', json_encode($asked)).':'.$page, self::KEEP_SECONDS, fn (): array => $tenant->run($shopId, fn (): array => [
            'found' => $search->handle($shopId, $asked['q'], $asked['compact'] ? self::COMPACT_PER_PAGE : self::PER_PAGE, $page, $store, $asked['cat'] ?: null),
            'policies' => $front ? AgentCatalog::policies() : ['promises' => [], 'pages' => []],
            'browse' => $front ? AgentCatalog::browse($store, self::COMPACT_PER_PAGE) : null,
        ]));
        $headers = ['X-Robots-Tag' => 'noindex', 'Cache-Control' => 'public, max-age='.self::KEEP_SECONDS];

        if ($request->query('format') === 'json') {
            return response()->json(self::forAi($store, $data, $asked), 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        $locale = in_array($store['locale'], ['he', 'en'], true) ? $store['locale'] : 'he';

        return response()->view('search::catalog', [
            'ai' => self::forAi($store, $data, $asked),
            'store' => $store,
            'c' => $data['found'],
            'policies' => $data['policies'],
            'locale' => $locale,
            'connector' => AgentCatalog::publicUrl('api/v1/mcp/'.$site),
        ], 200, $headers);
    }
}
