<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\CartLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /c/{site}/cart?items=PRODUCT:QTY,PRODUCT/OPTION:QTY[&format=json]
 *
 * The order a shopper's AI put together, as one link: opened, it goes straight to the store's
 * checkout with every item in the cart (Shopify), or to the store with the item added
 * (WooCommerce, one item a link). When an option must still be chosen (a size, a colour), a short
 * page lists the options, each a link that completes the order. As JSON: the link and what is
 * missing, for the AI to check before it answers. Nothing is bought here.
 */
final class StoreCartController
{
    public function __invoke(Request $request, TenantContext $tenant, string $site): RedirectResponse|Response|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null || ! Features::enabled('search.agent_connector', $connection->shop_id)) {
            abort(404);
        }

        $items = self::items((string) $request->query('items', ''));
        $cart = $tenant->run((string) $connection->shop_id, fn (): array => $items === [] ? ['url' => null, 'links' => [], 'problems' => []] : CartLink::build($connection, $items));
        $store = AgentCatalog::store($connection);
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store'];

        if ($request->query('format') === 'json') {
            return response()->json($cart + ['ready' => $cart['url'] !== null && $cart['problems'] === []], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        // Everything chosen, and one link holds it all: to the checkout.
        if ($cart['url'] !== null && $cart['problems'] === [] && ($store['platform'] === 'shopify' || ($store['checkout_link'] ?? false) || count($cart['links']) === 1)) {
            return redirect()->away($cart['url'], 302, $headers);
        }

        $locale = in_array($store['locale'], ['he', 'en'], true) ? $store['locale'] : 'he';
        $chosen = fn (string $product, string $option): string => AgentCatalog::publicUrl('c/'.$site.'/cart?items='.rawurlencode(implode(',', array_map(
            fn (array $item): string => $item['product'] === $product ? $product.'/'.$option.':'.$item['quantity'] : $item['product'].(($item['variant'] ?? '') !== '' ? '/'.$item['variant'] : '').':'.$item['quantity'],
            $items,
        ))));

        return response()->view('search::cart', ['store' => $store, 'cart' => $cart, 'locale' => $locale, 'chosen' => $chosen], 200, $headers);
    }

    /**
     * "31313:1,7677/42928:2": each product, its option when it has one, and how many.
     *
     * @return list<array{product: string, variant: string, quantity: int}>
     */
    public static function items(string $text): array
    {
        $items = [];

        foreach (array_slice(array_filter(array_map('trim', explode(',', $text))), 0, CartLink::MAX_ITEMS) as $part) {
            if (preg_match('~^([A-Za-z0-9_.-]{1,64})(?:/([A-Za-z0-9_.-]{1,64}))?(?::(\d{1,3}))?$~', $part, $m) !== 1) {
                continue;
            }
            $items[] = ['product' => $m[1], 'variant' => $m[2] ?? '', 'quantity' => max(1, min(CartLink::MAX_QUANTITY, (int) ($m[3] ?? 1)))];
        }

        return $items;
    }
}
