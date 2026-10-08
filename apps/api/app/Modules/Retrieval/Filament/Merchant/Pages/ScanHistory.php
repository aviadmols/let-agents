<?php

namespace App\Modules\Retrieval\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Retrieval\Filament\Operator\Pages\ScanHistory as OperatorScanHistory;
use UnitEnum;

/**
 * The shop's scanned pictures and what was seen in each: the same screen the operator uses, with
 * the shop fixed to the one in the address and nothing about models or runs.
 */
final class ScanHistory extends OperatorScanHistory
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'search';

    protected static ?int $navigationSort = 30;

    /** The shop opens on the pictures, as a gallery. */
    public string $display = 'gallery';
}
