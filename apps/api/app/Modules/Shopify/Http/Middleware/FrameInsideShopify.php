<?php

namespace App\Modules\Shopify\Http\Middleware;

use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\ShopifySignatures;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may put a page of ours in a frame: for the app's own pages and for a Shopify shop's panel,
 * that shop's admin and admin.shopify.com, nobody else, as Shopify requires of embedded apps.
 * Every other page is left as it was.
 */
final class FrameInsideShopify
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $shop = $this->shop($request);

        if ($shop !== null && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors https://{$shop} https://admin.shopify.com;");
        }

        return $response;
    }

    /** The myshopify address of the store this response is for, when it is for one. */
    private function shop(Request $request): ?string
    {
        if ($request->routeIs('shopify.*')) {
            return ShopifySignatures::shopDomain($request->query('shop'));
        }

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Shop || $tenant->platform !== ShopPlatform::Shopify) {
            return null;
        }

        $domain = ShopifyInstall::query()->where('shop_id', $tenant->getKey())->value('shop_domain');

        return is_string($domain) ? $domain : null;
    }
}
