<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Http\StorefrontSite;
use App\Modules\Search\Support\AgentCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * How an AI agent that reaches a store finds what we keep for it, without anyone giving it a link:
 *
 *   GET /api/v1/mcp/{site}/server-card   the store's MCP server, described (an MCP Server Card, SEP-2127 draft)
 *   GET /api/v1/search/{site}/llms.txt   the store for a language model (llms.txt): what it sells, the
 *                                         AI catalog, the MCP server, its policies and categories
 *
 * The store's own site points to them: the WooCommerce plugin serves /llms.txt and /.well-known/mcp*
 * from them, and every store's pages link them in their head. Read from what the nightly read keeps,
 * public like the catalog itself, cached for an hour. No model.
 */
final class AgentDiscoveryController
{
    private const CATEGORIES = 40;

    public function card(Request $request, string $site): JsonResponse
    {
        $connection = $this->connection($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $store = AgentCatalog::store($connection);
        $card = [
            'name' => 'co.lets.agents/'.$connection->site_key,
            'title' => $store['name'],
            'description' => __('search::agent.card_description', ['store' => $store['name']], AgentCatalog::AI_LOCALE),
            'version' => '1.0.0',
            'websiteUrl' => $store['url'],
            'remotes' => [['type' => 'streamable-http', 'url' => route('api.search.mcp', ['site' => $connection->site_key])]],
            'protocolVersions' => McpController::VERSIONS,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'authentication' => ['required' => false],
            'tools' => McpController::tools(AgentCatalog::AI_LOCALE),
            'documentation' => route('api.search.llms', ['site' => $connection->site_key]),
        ];

        return response()->json($card, 200, ['Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function llms(Request $request, TenantContext $tenant, string $site): Response|JsonResponse
    {
        $connection = $this->connection($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $text = $tenant->run((string) $connection->shop_id, fn (): string => $this->text($connection));

        return response($text, 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*']);
    }

    private function text(StoreConnection $connection): string
    {
        $store = AgentCatalog::store($connection);
        $locale = AgentCatalog::AI_LOCALE;
        $t = fn (string $key, array $params = []): string => (string) __('search::agent.llms.'.$key, $params, $locale);
        $products = CatalogProduct::query()->whereNull('removed_at')->count();
        $mcp = route('api.search.mcp', ['site' => $connection->site_key]);
        $lines = ['# '.$store['name'], '', '> '.$t('summary', ['store' => $store['name'], 'products' => (string) $products, 'url' => $store['url']]), '', '## '.$t('for_agents')];

        if ($store['catalog'] !== null) {
            $lines[] = '- ['.$t('catalog').']('.$store['catalog'].'): '.$t('catalog_about');
        }
        $lines[] = '- ['.$t('mcp').']('.$mcp.'): '.$t('mcp_about');
        $lines[] = '- ['.$t('card').']('.route('api.search.mcp_card', ['site' => $connection->site_key]).'): '.$t('card_about');

        $policies = AgentCatalog::policies();
        if ($policies['pages'] !== [] || $policies['promises'] !== []) {
            $lines = [...$lines, '', '## '.$t('policies')];
            foreach ($policies['pages'] as $page) {
                $lines[] = '- ['.$page['title'].']('.$page['url'].')';
            }
            foreach ($policies['promises'] as $promise) {
                $lines[] = '- '.$promise['name'].': '.$promise['detail'];
            }
        }

        $categories = CatalogCategory::query()->active()->where('product_count', '>', 0)->whereNotNull('url')
            ->orderByDesc('product_count')->orderBy('name')->limit(self::CATEGORIES)->get(['name', 'url', 'product_count']);
        if ($categories->isNotEmpty()) {
            $lines = [...$lines, '', '## '.$t('categories')];
            foreach ($categories as $category) {
                $lines[] = '- ['.$category->name.']('.$category->url.'): '.$t('category_count', ['count' => (string) $category->product_count]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** The store, when it lets agents in (the same switches as its MCP server). */
    private function connection(Request $request, string $site): StoreConnection|JsonResponse
    {
        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        if (! Features::enabled('search.storefront', $connection->shop_id) || ! Features::enabled('search.agent_connector', $connection->shop_id)) {
            return response()->json(['error' => 'connector_off'], 404);
        }

        return $connection;
    }
}
