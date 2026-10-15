<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Actions\SearchForAgent;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\CartLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * POST /api/v1/mcp
 *
 * Lets Search as one app inside a shopper's AI (a ChatGPT plugin, a Claude connector): one
 * address for every store that offers its catalogue to a shopper's AI, the store named in each
 * call. A Model Context Protocol server over plain HTTP, stateless, JSON-RPC answered with JSON,
 * no login: everything is the stores' public catalogues and what they approved of them. Tools to
 * find a store, search it, read a product and its policies, and make the one link that opens
 * the store's own checkout with the order in the cart; the products and the order also come as
 * cards (a ui:// widget). Nothing is bought, written or asked of the shopper here, and no model
 * of ours is asked.
 */
final class AppMcpController
{
    /** The cards a tool's answer is shown in, inside the AI. A new version is a new address. */
    public const WIDGET = 'ui://lets/products.v1.html';

    public const WIDGET_TYPE = 'text/html;profile=mcp-app';

    public function __invoke(Request $request, TenantContext $tenant, SearchForAgent $search): JsonResponse|Response
    {
        if (! $request->isMethod('POST')) {
            // No server-sent stream: every answer comes back with its request.
            return response('', 405, ['Allow' => 'POST']);
        }

        $message = json_decode((string) $request->getContent(), true);

        if (! is_array($message)) {
            return response()->json(self::failure(null, -32700, 'Parse error'));
        }

        if (array_is_list($message)) {
            $answers = array_values(array_filter(array_map(fn ($one) => is_array($one) ? $this->answer($one, $tenant, $search) : null, $message)));

            return $answers === [] ? response('', 202) : response()->json($answers, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $answer = $this->answer($message, $tenant, $search);

        return $answer === null ? response('', 202) : response()->json($answer, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null for a notification
     */
    private function answer(array $message, TenantContext $tenant, SearchForAgent $search): ?array
    {
        if (! array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];
        $params = (array) ($message['params'] ?? []);
        $t = fn (string $key, array $replace = []): string => (string) __('search::agent.app.'.$key, $replace, AgentCatalog::AI_LOCALE);

        return match ((string) ($message['method'] ?? '')) {
            'initialize' => self::result($id, [
                'protocolVersion' => in_array($params['protocolVersion'] ?? null, McpController::VERSIONS, true) ? $params['protocolVersion'] : McpController::VERSIONS[0],
                'capabilities' => ['tools' => ['listChanged' => false], 'resources' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'lets-search', 'title' => 'Lets Search', 'version' => '1.0.0'],
                'instructions' => $t('instructions'),
            ]),
            'ping' => self::result($id, (object) []),
            'tools/list' => self::result($id, ['tools' => self::tools()]),
            'tools/call' => $this->call($id, (string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []), $tenant, $search),
            'resources/list' => self::result($id, ['resources' => [['uri' => self::WIDGET, 'name' => 'products', 'title' => $t('widget_title'), 'mimeType' => self::WIDGET_TYPE]]]),
            'resources/templates/list' => self::result($id, ['resourceTemplates' => []]),
            'resources/read' => ($params['uri'] ?? null) === self::WIDGET
                ? self::result($id, ['contents' => [self::widget($tenant)]])
                : self::failure($id, -32002, 'Resource not found'),
            default => self::failure($id, -32601, 'Method not found'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call(mixed $id, string $tool, array $arguments, TenantContext $tenant, SearchForAgent $search): array
    {
        if ($tool === 'find_store') {
            $stores = self::stores()->filter(fn (StoreConnection $c): bool => self::matches($c, (string) ($arguments['query'] ?? '')))
                ->map(fn (StoreConnection $c): array => ['store' => (string) $c->site_key, 'name' => (string) ($c->shop?->name ?? ''), 'url' => (string) $c->site_url])
                ->values()->all();

            return self::answered($id, ['stores' => $stores], $stores === [] ? 'No store of Lets Search matches; ask the shopper for the store\'s web address.' : null);
        }

        if (! in_array($tool, ['search_products', 'get_product', 'store_policies', 'create_cart_link'], true)) {
            return self::failure($id, -32602, 'Unknown tool: '.$tool);
        }

        $connection = self::store((string) ($arguments['store'] ?? ''));

        if ($connection === null) {
            return self::answered($id, ['error' => 'unknown_store'], 'Unknown store. Call find_store with the store\'s name or web address, and pass its "store" value.');
        }

        $shopId = (string) $connection->shop_id;
        $store = AgentCatalog::store($connection);
        $data = $tenant->run($shopId, fn (): array => match ($tool) {
            'search_products' => $search->handle($shopId, (string) ($arguments['query'] ?? ''), (int) ($arguments['limit'] ?? 10), (int) ($arguments['page'] ?? 1), $store),
            'get_product' => ['products' => AgentCatalog::products([(string) ($arguments['id'] ?? '')], $store)] + ['as_of' => AgentCatalog::asOf([(string) ($arguments['id'] ?? '')])],
            'store_policies' => ['policies' => AgentCatalog::policies()],
            'create_cart_link' => CartLink::build($connection, array_values(array_filter((array) ($arguments['items'] ?? []), 'is_array'))),
        });

        if ($tool === 'get_product' && $data['products'] === []) {
            return self::answered($id, ['error' => 'unknown_product'], 'No such product in this store.');
        }

        $locale = in_array($store['locale'], ['he', 'en'], true) ? $store['locale'] : 'he';

        return self::answered($id, ['store' => ['store' => (string) $connection->site_key, 'name' => $store['name'], 'url' => $store['url']]] + $data, null, [
            // For the cards only: their words in the store's language.
            'labels' => trans('search::agent.cards', [], $locale),
            'locale' => $locale,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private static function answered(mixed $id, array $data, ?string $note = null, array $widget = []): array
    {
        return self::result($id, array_filter([
            'content' => [['type' => 'text', 'text' => $note ?? (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $data,
            '_meta' => $widget ?: null,
            'isError' => isset($data['error']),
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Every store in the app: its catalogue offered to a shopper's AI and its storefront on.
     *
     * @return Collection<int, StoreConnection>
     */
    public static function stores(): Collection
    {
        return app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->with('shop')->whereNotNull('site_key')
            ->whereHas('shop', fn ($shop) => $shop->where('status', 'active'))->orderBy('id')->get())
            ->filter(fn (StoreConnection $c): bool => Features::enabled('search.storefront', $c->shop_id)
                && Features::enabled('search.agent_connector', $c->shop_id) && Features::enabled('search.ai_share', $c->shop_id))
            ->values();
    }

    /** A store of the app by its id, its web address, or its name. */
    private static function store(string $named): ?StoreConnection
    {
        $named = trim($named);

        return $named === '' ? null : self::stores()->first(fn (StoreConnection $c): bool => $c->site_key === $named || self::matches($c, $named, true));
    }

    private static function matches(StoreConnection $connection, string $query, bool $exact = false): bool
    {
        $plain = fn (string $text): string => trim((string) preg_replace(['~^https?://~i', '~^www\.~i', '~/.*$~'], '', mb_strtolower(trim($text))));
        $host = $plain((string) $connection->site_url);
        $name = mb_strtolower(trim((string) ($connection->shop?->name ?? '')));
        $query = mb_strtolower(trim($query));

        if ($query === '') {
            return ! $exact;
        }

        $asked = $plain($query);

        return $exact
            ? $asked === $host || $query === $name || str_ends_with($host, '.'.$asked) || str_ends_with($asked, '.'.$host)
            : str_contains($host, $asked) || str_contains($name, $query) || str_contains($asked, $host);
    }

    /** @return list<array<string, mixed>> */
    public static function tools(): array
    {
        $t = fn (string $key): string => (string) __('search::agent.tools.'.$key, [], AgentCatalog::AI_LOCALE);
        $a = fn (string $key): string => (string) __('search::agent.app.'.$key, [], AgentCatalog::AI_LOCALE);
        $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $store = ['type' => 'string', 'description' => $a('store_argument')];
        $cards = fn (string $invoking, string $invoked): array => [
            'ui' => ['resourceUri' => self::WIDGET],
            'openai/outputTemplate' => self::WIDGET,
            'openai/toolInvocation/invoking' => $a($invoking),
            'openai/toolInvocation/invoked' => $a($invoked),
            'securitySchemes' => [['type' => 'noauth']],
        ];
        $noauth = ['securitySchemes' => [['type' => 'noauth']]];

        // The per-store tools, each with the store it asks.
        $tools = array_map(function (array $tool) use ($store, $read): array {
            $tool['inputSchema']['properties'] = ['store' => $store] + (array) $tool['inputSchema']['properties'];
            $tool['inputSchema']['required'] = ['store', ...($tool['inputSchema']['required'] ?? [])];
            $tool['annotations'] = $read;

            return $tool;
        }, McpController::tools(AgentCatalog::AI_LOCALE));

        $meta = [
            'search_products' => $cards('searching', 'searched'),
            'get_product' => $cards('reading', 'read'),
            'store_policies' => $noauth,
            'create_cart_link' => $cards('ordering', 'ordered'),
        ];

        return [
            ['name' => 'find_store', 'title' => $a('find_title'), 'description' => $a('find'), 'annotations' => $read, '_meta' => $noauth, 'inputSchema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string', 'description' => $a('find_query')]],
            ]],
            ...array_map(fn (array $tool): array => $tool + ['_meta' => $meta[$tool['name']]], $tools),
        ];
    }

    /**
     * The cards: one HTML page, its script inline, that shows the products of an answer with their
     * picture, price and a button to the store, and an order as one button to the store's checkout.
     * Pictures come from the stores' own hosts, and the buttons open the stores' own addresses.
     *
     * @return array<string, mixed>
     */
    private static function widget(TenantContext $tenant): array
    {
        $hosts = Cache::remember('search:app-widget-hosts', 3600, function () use ($tenant): array {
            $hosts = [];

            foreach (self::stores() as $connection) {
                $hosts[] = 'https://'.parse_url((string) $connection->site_url, PHP_URL_HOST);
                $tenant->run((string) $connection->shop_id, function () use (&$hosts): void {
                    CatalogProduct::query()->whereNull('removed_at')->whereNotNull('image_url')->limit(200)->pluck('image_url')
                        ->each(function ($url) use (&$hosts): void {
                            $host = parse_url((string) $url, PHP_URL_HOST);
                            if (is_string($host) && $host !== '') {
                                $hosts[] = 'https://'.$host;
                            }
                        });
                });
            }

            $hosts = array_values(array_unique($hosts));
            sort($hosts, SORT_STRING);

            return $hosts;
        });
        $stores = self::stores()->map(fn (StoreConnection $c): string => 'https://'.parse_url((string) $c->site_url, PHP_URL_HOST))->unique()->sort()->values()->all();
        $ours = rtrim(AgentCatalog::publicUrl(''), '/');

        return [
            'uri' => self::WIDGET,
            'mimeType' => self::WIDGET_TYPE,
            'text' => (string) file_get_contents(__DIR__.'/../../resources/app/cards.html'),
            '_meta' => [
                'ui' => ['prefersBorder' => false, 'domain' => $ours, 'csp' => ['connectDomains' => [], 'resourceDomains' => $hosts]],
                'openai/widgetDescription' => (string) __('search::agent.app.widget_description', [], AgentCatalog::AI_LOCALE),
                'openai/widgetPrefersBorder' => false,
                'openai/widgetDomain' => $ours,
                // The buttons open the stores' own pages and checkouts, and our order link.
                'openai/widgetCSP' => ['connect_domains' => [], 'resource_domains' => $hosts, 'redirect_domains' => [...$stores, $ours]],
            ],
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
}
