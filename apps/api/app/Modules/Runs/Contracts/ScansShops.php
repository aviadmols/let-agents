<?php

namespace App\Modules\Runs\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * The shop manager's "scan changes and new products": the night's reading of what changed, started
 * now (FirstScan). Once in a while: false when one ran too recently.
 */
interface ScansShops
{
    public function scanChanges(Shop $shop): bool;

    public function canScan(Shop $shop): bool;

    /** Hours a manager waits between two scans. */
    public function againAfterHours(): int;
}
