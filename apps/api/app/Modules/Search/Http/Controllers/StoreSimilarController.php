<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Actions\SearchCatalog;
use App\Modules\Search\Support\AgentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * GET /c/{site}/similar/{product}
 *
 * The products that look like one, for a shopper's AI ("something like this one"): from the
 * vectors of the store's pictures, already made at night, of the same kind only; by meaning when
 * the pictures were not scanned. No model is asked and no picture is read now. JSON, as the AI
 * reads every other page of the store, with each product's look and buy link.
 */
final class StoreSimilarController
{
    public const LIMIT = 12;

    public function __invoke(TenantContext $tenant, SearchCatalog $search, string $site, string $product): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null || ! Features::enabled('search.agent_connector', $connection->shop_id) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $product) !== 1) {
            abort(404);
        }

        $shopId = (string) $connection->shop_id;
        $store = AgentCatalog::store($connection);

        $data = Cache::remember('search:agent-similar:'.$site.':'.$product, 600, fn (): array => $tenant->run($shopId, function () use ($search, $shopId, $product, $store): array {
            $ids = array_values(array_filter(array_map(fn (array $p): string => (string) ($p['external_id'] ?? ''), $search->similar($shopId, $product, self::LIMIT))));

            return AgentCatalog::forAi($store, AgentCatalog::products($ids, $store), AgentCatalog::asOf($ids), ['like' => AgentCatalog::products([$product], $store)[0]['title'] ?? $product]);
        }));

        return response()->json($data, 200, ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'public, max-age=600'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
