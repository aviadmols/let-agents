<?php

namespace App\Modules\Shopify\Http\Middleware;

use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifySessionToken;
use App\Modules\Tenancy\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop's panel inside the Shopify admin sends each of its requests with the session token App Bridge
 * hands it (Authorization: Bearer, the embed view), as Shopify requires of embedded apps. Where one comes,
 * it must be good: signed by one of our apps for a store, not expired, and for a store the signed-in person
 * may open. A bad or expired one is refused with 401, and the frame enters again with a fresh one. A request
 * without one (the panel outside Shopify, a token that did not come in time) goes on as before, by its cookie.
 * The API's own keys are not this: /api is left alone.
 */
final class CheckShopifySessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '' || $request->is('api/*')) {
            return $next($request);
        }

        $claims = ShopifySessionToken::claims($token);

        if ($claims === null) {
            return response()->json(['message' => 'Invalid session token.'], 401);
        }

        $user = Auth::user();
        $shopId = ShopifyInstall::query()->where('shop_domain', $claims['shop'])->value('shop_id');
        $shop = is_string($shopId) ? Shop::query()->find($shopId) : null;

        // signed in as someone who may not open this store's panel: not this store's request
        if ($user instanceof User && ($shop === null || ! $user->canAccessTenant($shop))) {
            return response()->json(['message' => 'This session token is for another store.'], 403);
        }

        return $next($request);
    }
}
