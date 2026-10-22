<?php

namespace App\Modules\Retrieval\Support;

use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Tenancy\Models\Shop;

/**
 * How far the scan read, for the first-run screens: the products whose main picture was looked at, and
 * the pages and posts read into the index. Of what the store still publishes. Asked inside the shop.
 */
final class RetrievalShopFacts implements ShopFacts
{
    public function shopFacts(Shop $shop): array
    {
        return [
            'pictures_read' => RetrievalImage::query()
                ->whereNull('error')
                ->where(fn ($q) => $q->whereNotNull('embedded_at')->orWhereNotNull('captioned_at'))
                ->whereIn('product_id', CatalogProduct::query()->active()->select('id'))
                ->count(),
            'pages_read' => RetrievalChunk::query()
                ->where('source', 'content')
                ->whereNotNull('embedded_at')
                ->whereIn('source_id', CatalogContent::query()->active()->where('type', '!=', CatalogContent::KNOWLEDGE)->select('id'))
                ->distinct()
                ->count('source_id'),
        ];
    }
}
