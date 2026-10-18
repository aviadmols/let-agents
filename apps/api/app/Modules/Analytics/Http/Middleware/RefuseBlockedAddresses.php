<?php

namespace App\Modules\Analytics\Http\Middleware;

use App\Modules\Analytics\Models\BlockedIp;
use App\Modules\Connections\Models\StoreConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shopper-facing request (search, the assistant, saves, the beacons) from an address the shop blocked
 * on its analytics screen is refused before it is counted or answered. The store's own site, the plugin
 * and the order reports are not touched.
 */
final class RefuseBlockedAddresses
{
    /** The storefront's paths under /api/v1, each followed by the site's key. */
    private const PATHS = '#^api/v1/(search|widget|shortlist|visit|mcp)/([^/]+)#';

    public function handle(Request $request, Closure $next): Response
    {
        if (preg_match(self::PATHS, $request->path(), $m) === 1 && ($ip = $request->ip()) !== null) {
            $connection = StoreConnection::forSite(rawurldecode($m[2]));

            if ($connection !== null && in_array($ip, BlockedIp::forShop((string) $connection->shop_id), true)) {
                return response()->json(['error' => 'blocked'], 403);
            }
        }

        return $next($request);
    }
}
