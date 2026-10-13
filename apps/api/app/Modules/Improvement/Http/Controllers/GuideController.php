<?php

namespace App\Modules\Improvement\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Improvement\Models\ImprovementPost;
use App\Modules\Improvement\Support\PostDocument;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\Response;

/**
 * GET /g/{shop}/{post} — a published post of the store's own, as a shopper reads it: the guide,
 * and the store's products it is about, each linking to the store, its questions, and the data
 * search engines read (FAQPage, BlogPosting).
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

        $document = new PostDocument($guide, $products, (string) $store->name);
        $url = route('improvement.guide', ['shop' => $store->slug, 'post' => $guide->id]);

        return response(view('improvement::guide', [
            'shop' => $store,
            'post' => $guide,
            'document' => $document,
            'html' => $document->body(),
            'products' => $products,
            'rtl' => $document->rtl(),
            'schemas' => array_values(array_filter([$document->articleSchema($url), $document->faqSchema()])),
        ]))->header('Cache-Control', 'public, max-age=300');
    }
}
