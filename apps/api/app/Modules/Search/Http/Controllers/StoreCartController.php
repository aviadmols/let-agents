<?php

namespace App\Modules\Search\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
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
    /** How alike (percent) a name and a title must be to be the same product. */
    private const SAME_NAME = 85.0;

    public function __invoke(Request $request, TenantContext $tenant, string $site): RedirectResponse|Response|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null || ! Features::enabled('search.agent_connector', $connection->shop_id)) {
            abort(404);
        }

        $items = self::items((string) $request->query('items', ''));
        $cart = $tenant->run((string) $connection->shop_id, function () use (&$items, $connection): array {
            // A product the AI named instead of giving its id: the store's product of that name.
            $items = self::named($items);

            return $items === [] ? ['url' => null, 'links' => [], 'problems' => []] : CartLink::build($connection, $items);
        });
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
     * "31313:1,7677/42928:2": each product, its option when it has one, and how many. A part that
     * is not an id is a product's name, as an AI that lost the ids may write it ("מטאטא דשא:2"),
     * found by named().
     *
     * @return list<array{product: string, variant: string, quantity: int, name?: string}>
     */
    public static function items(string $text): array
    {
        $items = [];

        foreach (array_slice(array_filter(array_map('trim', explode(',', $text))), 0, CartLink::MAX_ITEMS) as $part) {
            if (preg_match('~^([A-Za-z0-9_.-]{1,64})(?:/([A-Za-z0-9_.-]{1,64}))?(?::(\d{1,3}))?$~', $part, $m) === 1) {
                $items[] = ['product' => $m[1], 'variant' => $m[2] ?? '', 'quantity' => max(1, min(CartLink::MAX_QUANTITY, (int) ($m[3] ?? 1)))];

                continue;
            }
            if (preg_match('~^(.{2,160}?)(?:\s*[:*]\s*(\d{1,3}))?$~u', $part, $m) === 1) {
                $items[] = ['product' => '', 'variant' => '', 'quantity' => max(1, min(CartLink::MAX_QUANTITY, (int) ($m[2] ?? 1))), 'name' => trim($m[1])];
            }
        }

        return $items;
    }

    /**
     * Each named item as the store's product of that name: the same words in any order, without
     * quotes or punctuation, or else the closest title when it is close enough to be the same
     * product. A name like no product stays as it is, and the order says the store has no such
     * product. Inside the shop's tenant.
     *
     * @param  list<array{product: string, variant: string, quantity: int, name?: string}>  $items
     * @return list<array{product: string, variant: string, quantity: int}>
     */
    private static function named(array $items): array
    {
        if (! in_array(true, array_map(fn (array $item): bool => isset($item['name']), $items), true)) {
            return $items;
        }

        $plain = function (string $text): string {
            $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}.\/]+/u', ' ', mb_strtolower(str_replace('+', ' ', $text)))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            sort($words, SORT_STRING);

            return implode(' ', $words);
        };
        $titles = CatalogProduct::query()->whereNull('removed_at')->orderBy('id')->pluck('title', 'external_id')
            ->map(fn ($title): string => $plain((string) $title))->all();

        return array_map(function (array $item) use ($plain, $titles): array {
            if (! isset($item['name'])) {
                return $item;
            }
            $wanted = $plain($item['name']);
            $found = array_search($wanted, $titles, true);

            if ($found === false) {
                $best = 0.0;
                foreach ($titles as $id => $title) {
                    similar_text($wanted, $title, $percent);
                    if ($percent > $best) {
                        [$best, $found] = [$percent, $id];
                    }
                }
                $found = $best >= self::SAME_NAME ? $found : false;
            }

            return ['product' => $found === false ? mb_substr($item['name'], 0, 64) : (string) $found, 'variant' => '', 'quantity' => $item['quantity']];
        }, $items);
    }
}
