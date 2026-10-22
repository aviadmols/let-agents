<?php

namespace App\Modules\Search\Support;

use App\Modules\Admin\Contracts\ShopFacts;
use App\Modules\Search\Models\SearchIndex;
use App\Modules\Tenancy\Models\Shop;

/** How many products the store's search holds now, for "try it now" on the first-run screens. Asked inside the shop. */
final class SearchShopFacts implements ShopFacts
{
    public function shopFacts(Shop $shop): array
    {
        return SearchIndex::query()->exists() ? ['searchable' => SearchScanDetails::products()] : [];
    }
}
