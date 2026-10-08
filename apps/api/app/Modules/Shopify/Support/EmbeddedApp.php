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

        $install = ShopifyInstall::query()->where('shop_id', $tenant->getKey())->first(['id', 'client_id']);

        if ($install === null) {
            return '';
        }

        return view('shopify::embed', ['key' => ShopifyApps::key($install->client_id)])->render();
    }
}
