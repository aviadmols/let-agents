<?php

namespace App\Modules\Shopify\Support;

use App\Modules\Admin\Models\User;
use App\Modules\Shopify\Models\ShopifyInstall;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * The app as it lives inside the Shopify admin: the shop's panel in a frame on admin.shopify.com.
 * A page knows it is there by what Shopify appends to the first address (embedded, host) and by
 * what the browser says of every frame load (Sec-Fetch-Dest). Such a page carries App Bridge,
 * the admin's bridge to the frame, and keeps Shopify's "host" on every link.
 */
final class EmbeddedApp
{
    /** Shopify's "host": the admin's address, base64 (letters, digits, -, _, =). */
    public const HOST = '/^[A-Za-z0-9_\-=]{4,300}$/';

    /** Where the session keeps a shop's "host" (head(), FrameInsideShopify). */
    public const HOST_KEY = 'shopify_host.';

    /** Whether this request is a page inside the Shopify admin. */
    public static function framed(Request $request): bool
    {
        return $request->query('embedded') === '1'
            || $request->filled('host')
            || $request->headers->get('Sec-Fetch-Dest') === 'iframe';
    }

    /** What the head of a merchant panel page inside the admin carries; nothing anywhere else. */
    public static function head(): string
    {
        $request = request();

        if (! self::framed($request) || Filament::getCurrentPanel()?->getId() !== User::MERCHANT_PANEL) {
            return '';
        }

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Shop || $tenant->platform !== ShopPlatform::Shopify) {
            return '';
        }

        $install = ShopifyInstall::query()->where('shop_id', $tenant->getKey())->first(['id', 'client_id', 'shop_domain']);

        if ($install === null) {
            return '';
        }

        // Shopify's "host" for this shop, kept with the session from the page that has it: a page of the panel reached
        // without it is sent back with it before it loads (FrameInsideShopify), so App Bridge, the page's first script,
        // finds it in the address (App Store 2.2.3)
        $host = $request->query('host');
        if (is_string($host) && preg_match(self::HOST, $host) === 1 && $request->hasSession()) {
            $request->session()->put(self::HOST_KEY.$install->shop_domain, $host);
        }

        return view('shopify::embed', ['key' => ShopifyApps::key($install->client_id), 'shop' => (string) $install->shop_domain])->render();
    }
}
