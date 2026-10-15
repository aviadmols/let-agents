<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Actions\SearchForAgent;
use App\Modules\Search\Http\StorefrontSite;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\CartLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/mcp/{site}
 *
 * The store as a connector for a shopper's own AI (Claude, ChatGPT…): a Model Context Protocol
 * server over plain HTTP, stateless, answering JSON-RPC with JSON. Four tools: search the store,
 * read a product, read its policies, and make a link that puts products in the store's own cart.
 * Everything is the store's public catalogue and what it approved of it; nothing is bought here,
 * no payment detail is asked for, and no model of ours is asked. Prices and stock as of the last
 * sync; the cart on the store has the final word.
 */
final class McpController
{
    /** The protocol versions this server speaks, newest first. */
    public const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public function __invoke(Request $request, TenantContext $tenant, SearchForAgent $search, string $site): JsonResponse|Response
    {
        if (! $request->isMethod('POST')) {
            // No server-sent stream: every answer comes back with its request.
            return response('', 405, ['Allow' => 'POST']);
        }

        $connection = StorefrontSite::resolve($request, $site);

        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        if (! Features::enabled('search.storefront', $connection->shop_id) || ! Features::enabled('search.agent_connector', $connection->shop_id)) {
            return response()->json(['error' => 'connector_off'], 404);
        }

        $message = json_decode((string) $request->getContent(), true);

        if (! is_array($message)) {
            return self::error(null, -32700, 'Parse error');
        }

        // An older client may send a batch.
        if (array_is_list($message)) {
            $answers = array_values(array_filter(array_map(fn ($one) => is_array($one) ? $this->answer($one, $connection, $tenant, $search) : null, $message)));

            return $answers === [] ? response('', 202) : response()->json($answers, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $answer = $this->answer($message, $connection, $tenant, $search);

        return $answer === null ? response('', 202) : response()->json($answer, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null for a notification
     */
    private function answer(array $message, StoreConnection $connection, TenantContext $tenant, SearchForAgent $search): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = (array) ($message['params'] ?? []);

        if (! array_key_exists('id', $message)) {
            return null;
        }

        $store = AgentCatalog::store($connection);
        // What the AI is told is in English; it answers the shopper in the shopper's own language.
        $locale = AgentCatalog::AI_LOCALE;

        return match ($method) {
            'initialize' => self::result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? null, self::VERSIONS, true) ? $params['protocolVersion'] : self::VERSIONS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'lets-search', 'title' => $store['name'], 'version' => '1.0.0'],
                'instructions' => __('search::agent.instructions', ['store' => $store['name'], 'url' => $store['url']], $locale),
            ]),
            'ping' => self::result($id, (object) []),
            'tools/list' => self::result($id, ['tools' => self::tools($locale)]),
            'tools/call' => $this->call($id, (string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []), $connection, $tenant, $search),
            default => self::failure($id, -32601, 'Method not found'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call(mixed $id, string $tool, array $arguments, StoreConnection $connection, TenantContext $tenant, SearchForAgent $search): array
    {
        $shopId = (string) $connection->shop_id;

        $data = $tenant->run($shopId, fn (): ?array => match ($tool) {
            'search_products' => ['store' => AgentCatalog::store($connection)] + $search->handle($shopId, (string) ($arguments['query'] ?? ''), (int) ($arguments['limit'] ?? 10), (int) ($arguments['page'] ?? 1), AgentCatalog::store($connection)),
            'get_product' => (AgentCatalog::products([(string) ($arguments['id'] ?? '')], AgentCatalog::store($connection))[0] ?? ['error' => 'unknown_product']) + ['as_of' => AgentCatalog::asOf([(string) ($arguments['id'] ?? '')])],
            'store_policies' => ['store' => AgentCatalog::store($connection), 'policies' => AgentCatalog::policies()],
            'create_cart_link' => CartLink::build($connection, array_values(array_filter((array) ($arguments['items'] ?? []), 'is_array'))),
            default => null,
        });

        if ($data === null) {
            return self::failure($id, -32602, 'Unknown tool: '.$tool);
        }

        return self::result($id, [
            'content' => [['type' => 'text', 'text' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
            'structuredContent' => $data,
            'isError' => isset($data['error']),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function tools(string $locale): array
    {
        $t = fn (string $key): string => (string) __('search::agent.tools.'.$key, [], $locale);
        $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        return [
            ['name' => 'search_products', 'title' => $t('search_title'), 'description' => $t('search'), 'annotations' => $read, 'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => $t('search_query')],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => SearchForAgent::MAX, 'default' => 10],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => $t('search_page')],
                ],
            ]],
            ['name' => 'get_product', 'title' => $t('product_title'), 'description' => $t('product'), 'annotations' => $read, 'inputSchema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'string', 'description' => $t('product_id')]],
                'required' => ['id'],
            ]],
            ['name' => 'store_policies', 'title' => $t('policies_title'), 'description' => $t('policies'), 'annotations' => $read, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'create_cart_link', 'title' => $t('cart_title'), 'description' => $t('cart'), 'annotations' => $read, 'inputSchema' => [
                'type' => 'object',
                'properties' => ['items' => ['type' => 'array', 'maxItems' => CartLink::MAX_ITEMS, 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'product' => ['type' => 'string', 'description' => $t('cart_product')],
                        'variant' => ['type' => 'string', 'description' => $t('cart_variant')],
                        'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => CartLink::MAX_QUANTITY, 'default' => 1],
                    ],
                    'required' => ['product'],
                ]]],
                'required' => ['items'],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function failure(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(self::failure($id, $code, $message));
    }
}
