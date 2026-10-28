<?php

namespace App\Modules\Catalog\Support;

use App\Core\Facades\Settings;
use App\Modules\Admin\Contracts\ScanProgress;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;

/**
 * How far the catalog's steps of the scan are, counted from what is stored: the products read from the store (of
 * as many as the store says it publishes, where its plugin says it, up to what the shop may hold), and the site's
 * pages read so far. Inside the shop.
 */
final class CatalogScanProgress implements ScanProgress
{
    public function scanProgress(Shop $shop, array $began): array
    {
        $products = CatalogProduct::query()->whereNull('removed_at')->count();
        $published = StoreConnection::query()->first()?->info('counts.products.publish');
        $cap = (int) Settings::get('catalog.max_products', $shop->id);
        $total = is_numeric($published) && (int) $published > 0 ? ($cap > 0 ? min($cap, (int) $published) : (int) $published) : null;

        $progress = ['catalog' => ['done' => $total === null ? $products : min($products, $total), 'total' => $total]];

        // the site's own pages read in this scan: a new one or a changed one (an unchanged page writes nothing)
        if (isset($began['site'])) {
            $progress['site'] = ['done' => CatalogContent::query()->where('external_id', 'like', CatalogContent::OWN_PREFIX.'site-%')->where('synced_at', '>=', $began['site'])->count(), 'total' => null];
        }

        return $progress;
    }
}
