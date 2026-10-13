<?php

namespace App\Modules\Shortlist\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shortlist\Actions\BuildComparison;
use App\Modules\Shortlist\Actions\KeepWishlist;
use App\Modules\Shortlist\Actions\ProductCards;
use App\Modules\Shortlist\Models\ShortlistCount;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the storefront's compare and wishlist buttons ask for, all public and all code: no model
 * is called here. Both are off until the shop turns them on (shortlist.compare, shortlist.wishlist).
 *
 *   GET  /api/v1/shortlist/{site}/config            what is on, where the buttons go, the labels
 *   GET  /api/v1/shortlist/{site}/compare?ids=…      the comparison table, from approved facts
 *   GET  /api/v1/shortlist/{site}/paths              product address → id, for the theme's own cards
 *   POST /api/v1/shortlist/{site}/wishlist           a browser's list: read it, add, remove
 *   GET  /api/v1/shortlist/{site}/shared/{code}      a shared list, read-only
 *   ANY  /api/v1/shortlist/proxy/wishlist            the same list through Shopify's app proxy,
 *                                                   with the signed-in customer Shopify vouches for
 */
final class ShortlistController
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function config(Request $request, string $site): JsonResponse
    {
        $connection = self::site($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;
        $locale = self::locale($request);
        $compare = Features::enabled('shortlist.compare', $shopId);
        $wishlist = Features::enabled('shortlist.wishlist', $shopId);

        $config = ['compare' => $compare, 'wishlist' => $wishlist];
        if ($compare || $wishlist) {
            $config += [
                'compareMax' => (int) Settings::get('shortlist.compare_max', $shopId),
                'cardSelector' => trim((string) Settings::get('shortlist.card_selector', $shopId)),
                'productSelector' => trim((string) Settings::get('shortlist.product_selector', $shopId)),
                'wishlistPlace' => $wishlist ? trim((string) Settings::get('shortlist.wishlist_place', $shopId)) : '',
                'wishlistFloating' => (bool) Settings::get('shortlist.wishlist_floating', $shopId),
                'wishlistHeader' => $wishlist && (bool) Settings::get('shortlist.wishlist_header', $shopId),
                'wishlistWhatsapp' => (bool) Settings::get('shortlist.wishlist_whatsapp', $shopId),
                // For the share message: "My wishlist at Rocks and Gold".
                'shopName' => (string) (Shop::query()->whereKey($shopId)->value('name') ?? ''),
                'labels' => trans('shortlist::storefront', [], $locale),
            ];
        }

        return response()->json($config, 200, ['Cache-Control' => 'public, max-age=300'], self::JSON);
    }

    public function compare(Request $request, TenantContext $tenant, BuildComparison $comparison, string $site): JsonResponse
    {
        $connection = self::site($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;
        if (! Features::enabled('shortlist.compare', $shopId)) {
            return response()->json(['error' => 'compare_off'], 404);
        }

        $ids = ProductCards::ids($request->query('ids', ''), (int) Settings::get('shortlist.compare_max', $shopId));
        $table = $tenant->run($shopId, function () use ($comparison, $ids, $request, $shopId): array {
            $table = $comparison->handle($ids, self::locale($request));
            // Counted for the shop's screen: the comparison, and each product in it.
            if (count($table['products']) >= 2) {
                ShortlistCount::add($shopId, ShortlistCount::COMPARE, array_column($table['products'], 'id'));
            }

            return $table;
        });

        return response()->json($table, 200, ['Cache-Control' => 'public, max-age=300'], self::JSON);
    }

    public function paths(Request $request, TenantContext $tenant, string $site): JsonResponse
    {
        $connection = self::site($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;
        if (! Features::enabled('shortlist.compare', $shopId) && ! Features::enabled('shortlist.wishlist', $shopId)) {
            return response()->json(['error' => 'shortlist_off'], 404);
        }

        $paths = $tenant->run($shopId, function (): array {
            $paths = [];
            foreach (CatalogProduct::query()->active()->whereNotNull('url')->select(['id', 'external_id', 'url'])->orderBy('id')->toBase()->lazy(500) as $product) {
                $path = self::path((string) $product->url);
                if ($path !== null) {
                    $paths[$path] = (string) $product->external_id;
                }
            }

            return $paths;
        });

        return response()->json(['paths' => (object) $paths], 200, ['Cache-Control' => 'public, max-age=300'], self::JSON);
    }

    public function wishlist(Request $request, TenantContext $tenant, KeepWishlist $keep, ProductCards $cards, string $site): JsonResponse
    {
        $connection = self::site($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $customer = is_string($request->input('customer')) ? $connection->customerFromProof((string) $request->input('customer')) : null;

        return self::keep($request, $tenant, $keep, $cards, $connection->shop_id, $customer);
    }

    /** Through Shopify's app proxy: the store and its signed-in customer, both signed by Shopify. */
    public function proxy(Request $request, TenantContext $tenant, KeepWishlist $keep, ProductCards $cards): JsonResponse
    {
        $install = ShopifyInstall::fromProxy($request->query());
        if ($install === null) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        $customer = preg_match('/^[0-9]{1,20}$/', (string) $request->query('logged_in_customer_id', '')) === 1 ? 'shopify:'.$request->query('logged_in_customer_id') : null;

        return self::keep($request, $tenant, $keep, $cards, $install->shop_id, $customer);
    }

    public function shared(Request $request, TenantContext $tenant, KeepWishlist $keep, ProductCards $cards, string $site, string $code): JsonResponse
    {
        $connection = self::site($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $shopId = $connection->shop_id;
        if (! Features::enabled('shortlist.wishlist', $shopId)) {
            return response()->json(['error' => 'wishlist_off'], 404);
        }

        $products = $tenant->run($shopId, function () use ($keep, $cards, $code): ?array {
            $items = $keep->shared($code);

            return $items === null ? null : $cards->handle($items);
        });

        return $products === null
            ? response()->json(['error' => 'unknown_list'], 404)
            : response()->json(['products' => $products], 200, ['Cache-Control' => 'public, max-age=60'], self::JSON);
    }

    private static function keep(Request $request, TenantContext $tenant, KeepWishlist $keep, ProductCards $cards, string $shopId, ?string $customer): JsonResponse
    {
        if (! Features::enabled('shortlist.wishlist', $shopId)) {
            return response()->json(['error' => 'wishlist_off'], 404);
        }

        $max = (int) Settings::get('shortlist.wishlist_max', $shopId);
        $token = is_string($request->input('token')) ? (string) $request->input('token') : null;
        $add = ProductCards::ids($request->input('add', []), $max);
        $remove = ProductCards::ids($request->input('remove', []), $max);

        $list = $tenant->run($shopId, function () use ($keep, $cards, $shopId, $token, $customer, $add, $remove): array {
            $list = $keep->handle($shopId, $token, $customer, $add, $remove);

            return $list + ['products' => $cards->handle($list['items'])];
        });

        return response()->json($list, 200, ['Cache-Control' => 'no-store'], self::JSON);
    }

    /** The shop behind a public site key; a browser on another site is refused. */
    private static function site(Request $request, string $site): StoreConnection|JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        return $origin !== null && ! $connection->allowsOrigin($origin) ? response()->json(['error' => 'other_origin'], 403) : $connection;
    }

    private static function locale(Request $request): string
    {
        return in_array($request->query('locale'), ['he', 'en'], true) ? (string) $request->query('locale') : 'he';
    }

    /**
     * A product's address as the theme links to it: the path, decoded, lower case, with no trailing
     * slash. A Shopify card inside a collection links to /collections/x/products/y: the product part.
     */
    public static function path(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = mb_strtolower(rtrim(rawurldecode($path), '/'));
        if (preg_match('#/products/[^/]+$#u', $path, $m) === 1) {
            $path = $m[0];
        }

        return $path === '' ? null : $path;
    }
}
