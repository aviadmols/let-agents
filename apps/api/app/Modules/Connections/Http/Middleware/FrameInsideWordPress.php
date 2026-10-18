<?php

namespace App\Modules\Connections\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may put a WooCommerce shop's panel in a frame: that store's own addresses (wp-admin lives on
 * the site's host), and nobody else. The panel then runs inside wp-admin, opened by the plugin
 * (PluginPanelController). Every other page is left as it was.
 */
final class FrameInsideWordPress
{
    /** The shop a panel session was opened for from wp-admin. */
    public const SESSION = 'connections.framed_by';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->has('Content-Security-Policy')) {
            return $response;
        }

        $hosts = $this->hosts($request);

        if ($hosts !== []) {
            $response->headers->set('Content-Security-Policy', 'frame-ancestors '.implode(' ', $hosts).';');
        }

        return $response;
    }

    /** @return list<string> the origins that may frame this response, when it is a WooCommerce shop's panel */
    private function hosts(Request $request): array
    {
        $shop = Filament::getTenant();

        if (! $shop instanceof Shop && $request->hasSession() && is_string($id = $request->session()->get(self::SESSION))) {
            $shop = Shop::query()->find($id);
        }

        if (! $shop instanceof Shop || $shop->platform !== ShopPlatform::WooCommerce) {
            return [];
        }

        $connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $shop->getKey())->first());

        if ($connection === null) {
            return [];
        }

        $out = ["'self'"];

        foreach ($connection->allowedHosts() as $host) {
            array_push($out, 'https://'.$host, 'https://www.'.$host);
        }

        return $out;
    }
}
