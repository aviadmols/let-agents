<?php

namespace App\Modules\Shortlist\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Http\Visit;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shortlist\Actions\BuildComparison;
use App\Modules\Shortlist\Actions\KeepWishlist;
use App\Modules\Shortlist\Actions\PhoneSignIn;
use App\Modules\Shortlist\Actions\ProductCards;
use App\Modules\Shortlist\Models\ShortlistCount;
use App\Modules\Sms\Contracts\SendsSms;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the search drawer's compare and saved list ask for (lets-search.js), all public and all code:
 * no model is called here. Both are off until the shop turns them on (shortlist.compare, shortlist.wishlist).
 *
 *   GET  /api/v1/shortlist/{site}/config            what is on, sharing, sign-in by phone, the labels
 *   GET  /api/v1/shortlist/{site}/compare?ids=…      the comparison table, from approved facts
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
                'wishlistWhatsapp' => (bool) Settings::get('shortlist.wishlist_whatsapp', $shopId),
                // Keeping the list by phone, with a code by SMS: only where the shop turned it on and the platform can text.
                'phoneLogin' => $wishlist && Features::enabled('shortlist.wishlist_phone', $shopId) && app(SendsSms::class)->ready(),
                // For the share message: "My wishlist at Rocks and Gold".
                'shopName' => (string) (Shop::query()->whereKey($shopId)->value('name') ?? ''),
                // The words the drawer takes from the store: signing in by phone and the share message.
                'labels' => array_diff_key((array) trans('shortlist::storefront', [], $locale), ['phone_sms' => true]),
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

    public function wishlist(Request $request, TenantContext $tenant, KeepWishlist $keep, ProductCards $cards, string $site): JsonResponse
    {
        $connection = self::site($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $customer = is_string($request->input('customer')) ? $connection->customerFromProof((string) $request->input('customer')) : null;
        // Not signed in to the store: signed in by phone, when this browser was.
        if ($customer === null && is_string($request->input('phone')) && Features::enabled('shortlist.wishlist_phone', $connection->shop_id)) {
            $customer = $tenant->run($connection->shop_id, fn (): ?string => app(PhoneSignIn::class)->resolve($connection->shop_id, (string) $request->input('phone')));
        }

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

        $visit = Visit::from($request, is_array($request->input('ctx')) ? $request->input('ctx') : []);
        $list = $tenant->run($shopId, function () use ($keep, $cards, $shopId, $token, $customer, $add, $remove, $visit): array {
            $list = $keep->handle($shopId, $token, $customer, $add, $remove, $visit);
            // A list kept by phone says which, masked, for "kept for 050-•••-4567".
            $phone = $customer === null ? null : PhoneSignIn::maskedFor($customer);

            return $list + ['products' => $cards->handle($list['items'])] + ($phone === null ? [] : ['phone' => $phone]);
        });

        return response()->json($list, 200, ['Cache-Control' => 'no-store'], self::JSON);
    }

    /** A four-digit code by SMS, to keep the wishlist by phone. */
    public function phoneCode(Request $request, TenantContext $tenant, PhoneSignIn $signIn, string $site): JsonResponse
    {
        $connection = self::phoneSite($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $result = $tenant->run($connection->shop_id, fn (): array => $signIn->sendCode($connection->shop_id, (string) $request->input('phone', ''), (string) $request->ip()));

        return response()->json($result, $result['sent'] ? 200 : ($result['error'] === 'too_many' ? 429 : 422), ['Cache-Control' => 'no-store'], self::JSON);
    }

    /** The code back: the list is the number's, and the browser gets its sign-in token. */
    public function phoneVerify(Request $request, TenantContext $tenant, PhoneSignIn $signIn, string $site): JsonResponse
    {
        $connection = self::phoneSite($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $token = is_string($request->input('token')) ? (string) $request->input('token') : null;
        $result = $tenant->run($connection->shop_id, fn (): array => $signIn->verify($connection->shop_id, (string) $request->input('phone', ''), (string) $request->input('code', ''), $token, $request->boolean('consent')));

        return response()->json($result, $result['ok'] ? 200 : 422, ['Cache-Control' => 'no-store'], self::JSON);
    }

    public function phoneOut(Request $request, TenantContext $tenant, PhoneSignIn $signIn, string $site): JsonResponse
    {
        $connection = self::phoneSite($request, $site);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        if (is_string($request->input('phone'))) {
            $tenant->run($connection->shop_id, fn () => $signIn->signOut($connection->shop_id, (string) $request->input('phone')));
        }

        return response()->json(['ok' => true], 200, ['Cache-Control' => 'no-store'], self::JSON);
    }

    private static function phoneSite(Request $request, string $site): StoreConnection|JsonResponse
    {
        $connection = self::site($request, $site);

        if (! $connection instanceof JsonResponse && (! Features::enabled('shortlist.wishlist', $connection->shop_id) || ! Features::enabled('shortlist.wishlist_phone', $connection->shop_id))) {
            return response()->json(['error' => 'phone_off'], 404);
        }

        return $connection;
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
}
