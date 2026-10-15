<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\CartLink;
use App\Modules\Search\Support\OrderItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /c/{site}/cart?items=PRODUCT:QTY,PRODUCT/OPTION:QTY[&format=json]
 *
 * The order a shopper's AI put together, as one link (an older link: the AI is now given the
 * store's own ?la_order=…): opened, it goes straight to the store's checkout with every item in
 * the cart when one link holds it all; otherwise to the store's own page with the order, which our
 * script there completes (CalculateCart). As JSON: the link and what is missing, for the AI to
 * check before it answers. Nothing is bought here.
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
        $cart = $tenant->run((string) $connection->shop_id, function () use (&$items, $connection): array {
            // A product the AI named instead of giving its id: the store's product of that name.
            $items = OrderItems::named($items);

            return $items === [] ? ['url' => null, 'links' => [], 'problems' => [], 'on_store' => [], 'cart_url' => CartLink::cartUrl($connection)] : CartLink::build($connection, $items);
        });
        $store = AgentCatalog::store($connection);
        $headers = ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store'];

        if ($request->query('format') === 'json') {
            return response()->json($cart + [
                'ready' => ($cart['url'] !== null || $cart['on_store'] !== []) && $cart['problems'] === [],
                // Every item goes in by the link alone; else some are added on their own page.
                'one_link' => $cart['on_store'] === [],
            ], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        // Everything chosen, and one link holds it all: to the checkout.
        if ($cart['url'] !== null && $cart['problems'] === [] && $cart['on_store'] === [] && ($store['platform'] === 'shopify' || ($store['checkout_link'] ?? false) || count($cart['links']) === 1)) {
            return redirect()->away($cart['url'], 302, $headers);
        }

        // Anything else (an option or a length still to choose, a page's field, more than one line
        // on an older WooCommerce): the order on the store's own page, where our script shows it and
        // puts it in the cart. The shopper never stays on an address of ours.
        $line = fn (array $item): string => $item['product'].(($item['variant'] ?? '') !== '' ? '/'.$item['variant'] : '').(($item['length'] ?? null) !== null ? '@'.$item['length'] : '').':'.$item['quantity'];

        return redirect()->away(AgentCatalog::orderBase($store).rawurlencode(implode(',', array_map($line, $items))), 302, $headers);
    }

    /**
     * "31313:1,7677/42928:2,14283@5.4:4": each product, its option when it has one, the length in
     * metres when it is sold by length, and how many. A part that is not an id is a product's name,
     * as an AI that lost the ids may write it ("מטאטא דשא:2"), found by named().
     *
     * @return list<array{product: string, variant: string, quantity: int, length?: float, name?: string}>
     */
    public static function items(string $text): array
    {
        return OrderItems::parse($text);
    }
}
