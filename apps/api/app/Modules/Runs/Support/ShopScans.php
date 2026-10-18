<?php

namespace App\Modules\Runs\Support;

use App\Modules\Runs\Contracts\ScansShops;
use App\Modules\Tenancy\Models\Shop;

/** "Scan changes and new products", for the screens that offer it (the scan summary). */
final class ShopScans implements ScansShops
{
    public function scanChanges(Shop $shop): bool
    {
        return FirstScan::start($shop, full: false);
    }

    public function canScan(Shop $shop): bool
    {
        return FirstScan::canStart($shop);
    }

    public function againAfterHours(): int
    {
        return FirstScan::AGAIN_AFTER_HOURS;
    }
}
