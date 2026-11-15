<?php

namespace App\Modules\Shopify\Http\Middleware;

use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Shopify\Support\EmbeddedApp;
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

        // A page of a Shopify shop's panel loaded in the admin's frame without Shopify's "host" (the admin hands it to the
        // first page only): sent back with the one its session kept, before it loads. App Bridge is the page's first
        // script (App Store 2.2.3) and reads "host" from the address; nothing may run before it to put it there.
        if ($shop !== null && ! $request->routeIs('shopify.*') && ($back = $this->withHost($request, $shop, $response)) !== null) {
            return $back;
        }

        if ($shop !== null && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors https://{$shop} https://admin.shopify.com;");
        }

        return $response;
    }

    /** The same page with the shop's "host" kept in the session, when it is a full page loaded in the frame without it. */
    private function withHost(Request $request, string $shop, Response $response): ?Response
    {
        if (! $request->isMethod('GET') || $request->query('host') !== null || $request->headers->get('Sec-Fetch-Dest') !== 'iframe'
            || $request->hasHeader('X-Livewire') || ! $response->isSuccessful() || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || ! $request->hasSession()) {
            return null;
        }

        $host = $request->session()->get(EmbeddedApp::HOST_KEY.$shop);

        if (! is_string($host) || preg_match(EmbeddedApp::HOST, $host) !== 1) {
            return null;
        }

        return redirect()->to($request->fullUrlWithQuery(['host' => $host, 'shop' => $shop, 'embedded' => '1']));
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
