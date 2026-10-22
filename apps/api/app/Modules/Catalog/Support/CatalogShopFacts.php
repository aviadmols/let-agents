<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Tenancy\Models\Shop;

/** What the store publishes, for the first-run screens: its products, and its pages and posts. Asked inside the shop. */
final class CatalogShopFacts implements ShopFacts
{
    public function shopFacts(Shop $shop): array
    {
        return [
            'products' => CatalogProduct::query()->active()->count(),
            'pages' => CatalogContent::query()->active()->where('type', '!=', CatalogContent::KNOWLEDGE)->count(),
        ];
    }
}
