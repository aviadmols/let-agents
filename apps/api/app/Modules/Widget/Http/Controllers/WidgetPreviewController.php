<?php

namespace App\Modules\Widget\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /widget-preview/{shop}?id=...&locale=he|en&theme=lets|store
 *
 * A sample product page of the shop with the Lets Search drawer on it, the same file the stores
 * load, opening with "about this product" from the shop's real page bank and search index. What a
 * shopper would send stays in the page: no event is counted, no question reaches a model, no
 * visitor is remembered, no search is counted. For the display settings screen.
 */
final class WidgetPreviewController
{
    private const SAMPLES = 12;

    public function __invoke(Request $request, Shop $shop, TenantContext $tenant): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAccessTenant($shop), 404);

        // What the store's shoppers read: Hebrew unless asked otherwise, whatever the admin reads.
        $locale = $request->query('locale') === 'en' ? 'en' : 'he';
        $theme = $request->query('theme') === 'store' ? 'store' : 'lets';
        app()->setLocale($locale);

        [$samples, $page, $connection] = $tenant->run($shop->id, function () use ($request): array {
            $samples = CatalogProduct::query()->active()->where('in_stock', true)->orderByDesc('updated_at')->orderBy('id')->limit(self::SAMPLES)->get(['external_id', 'title', 'image_url', 'price', 'currency']);
            $id = (string) $request->query('id', '');

            return [$samples, $samples->firstWhere('external_id', $id) ?? $samples->first(), StoreConnection::query()->latest()->first()];
        });

        return response()->view('widget::preview.frame', [
            'shop' => $shop,
            'locale' => $locale,
            'theme' => $theme,
            'samples' => $samples,
            'page' => $page,
            'site' => $connection?->site_key,
            'previewKey' => $connection?->previewKey(),
            'on' => Features::enabled('widget.on_products', $shop->id),
            'script' => route('api.search.lets-search'),
            'api' => url('/api/v1'),
        ])->header('Cache-Control', 'no-store')
            // Framed by our own display screen only, which itself may sit inside the Shopify admin: every
            // ancestor is checked, so SAMEORIGIN alone refused it there.
            ->header('Content-Security-Policy', "frame-ancestors 'self' https://admin.shopify.com https://*.myshopify.com;");
    }
}
