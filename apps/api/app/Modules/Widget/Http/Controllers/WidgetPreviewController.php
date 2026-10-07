<?php

namespace App\Modules\Widget\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Actions\BuildPageBank;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /widget-preview/{shop}?type=product|content&id=...
 *
 * A sample page of the shop with the real widget on it, built from the shop's saved settings by
 * the same code that serves the storefront. Nothing leaves the page: no event is counted, no
 * question reaches a model, no visitor is remembered. For the display settings screen.
 */
final class WidgetPreviewController
{
    private const SAMPLES = 12;

    public function __invoke(Request $request, Shop $shop, TenantContext $tenant, BuildPageBank $bank): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canAccessTenant($shop), 404);

        $type = $request->query('type') === 'content' ? 'content' : 'product';
        // What the store's shoppers read: Hebrew unless asked otherwise, whatever the admin reads.
        $locale = $request->query('locale') === 'en' ? 'en' : 'he';
        app()->setLocale($locale);

        [$samples, $page] = $tenant->run($shop->id, function () use ($type, $request): array {
            $samples = $type === 'product'
                ? CatalogProduct::query()->active()->where('in_stock', true)->orderByDesc('updated_at')->orderBy('id')->limit(self::SAMPLES)->get(['external_id', 'title', 'image_url', 'price', 'currency'])
                : CatalogContent::query()->active()->orderByDesc('updated_at')->orderBy('id')->limit(self::SAMPLES)->get(['external_id', 'title', 'image_url']);
            $id = (string) $request->query('id', '');
            $page = $samples->firstWhere('external_id', $id) ?? $samples->first();

            return [$samples, $page];
        });

        $data = $page === null ? null : $bank->handle($shop->id, $type, (string) $page->external_id, $locale);

        return response()->view('widget::preview.frame', [
            'shop' => $shop,
            'type' => $type,
            'locale' => $locale,
            'samples' => $samples,
            'page' => $page,
            'bank' => $data,
            'script' => route('api.widget.script'),
            'api' => url('/api/v1'),
        ])->header('Cache-Control', 'no-store')->header('X-Frame-Options', 'SAMEORIGIN');
    }
}
