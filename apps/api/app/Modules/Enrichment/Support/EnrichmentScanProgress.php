<?php

namespace App\Modules\Enrichment\Support;

use App\Modules\Admin\Contracts\ScanProgress;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\ItemStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Enrichment\Models\EnrichmentBatchItem;
use App\Modules\Tenancy\Models\Shop;

/**
 * How far "understanding the product details" is: the products whose details were read in this scan, of the
 * store's products. Counted from each product's reading as it is answered; a reading sent to be answered later (in
 * a batch) counts when its answer comes. Inside the shop.
 */
final class EnrichmentScanProgress implements ScanProgress
{
    public function scanProgress(Shop $shop, array $began): array
    {
        $products = CatalogProduct::query()->active()->count();
        $read = isset($began['products'])
            ? EnrichmentBatchItem::query()->where('subject_type', 'product')
                ->whereIn('status', [ItemStatus::Applied->value, ItemStatus::Rejected->value, ItemStatus::Stale->value])
                ->where('updated_at', '>=', $began['products'])
                ->whereHas('batch', fn ($q) => $q->where('task', TaskType::ProductExtraction->value))
                ->distinct()->count('subject_id')
            : 0;

        return ['products' => ['done' => min($read, $products), 'total' => $products > 0 ? $products : null]];
    }
}
