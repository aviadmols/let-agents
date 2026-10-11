<?php

namespace App\Modules\Improvement\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * GET /g/{shop}/{post} — a published post of the store's own, as a shopper reads it: the guide,
 * and the store's products it is about, each linking to the store.
 */
final class GuideController
{
    public function __invoke(TenantContext $tenant, string $shop, string $post): Response
    {
        $store = Shop::query()->where('slug', $shop)->firstOrFail();
        $guide = $tenant->run($store->id, fn (): ?ImprovementPost => ImprovementPost::query()->whereKey($post)->where('status', ImprovementPost::PUBLISHED)->first());

        abort_if($guide === null, 404);

        $products = $tenant->run($store->id, fn () => CatalogProduct::query()->active()
            ->whereIn('external_id', $guide->products)
            ->get(['external_id', 'title', 'url', 'image_url'])
            ->sortBy(fn (CatalogProduct $p): int => (int) array_search((string) $p->external_id, $guide->products, true))
            ->values());

        return response(view('improvement::guide', [
            'shop' => $store,
            'post' => $guide,
            'html' => Str::markdown($guide->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'products' => $products,
            'rtl' => preg_match('/\p{Hebrew}/u', $guide->title.$guide->body) === 1,
        ]))->header('Cache-Control', 'public, max-age=300');
    }
}
