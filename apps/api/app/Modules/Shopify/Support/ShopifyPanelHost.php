<?php

namespace App\Modules\Shopify\Support;

use App\Modules\Admin\Contracts\PanelHost;
use Illuminate\Http\Request;

/**
 * The panel inside the Shopify admin: a page in its frame (EmbeddedApp::framed), or a request from that page with
 * the session token App Bridge hands it. Shopify signed the merchant in, so there is nothing to sign out of.
 */
final class ShopifyPanelHost implements PanelHost
{
    public function hosts(Request $request): bool
    {
        $token = $request->bearerToken();

        return EmbeddedApp::framed($request) || ($token !== null && $token !== '' && ShopifySessionToken::claims($token) !== null);
    }
}
