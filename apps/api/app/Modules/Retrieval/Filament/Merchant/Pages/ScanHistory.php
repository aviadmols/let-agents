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

    protected static string|UnitEnum|null $navigationGroup = 'scan';

    protected static ?int $navigationSort = 10;

    /** The scan summary shows each product's picture; the gallery stays reachable from its link. */
    protected static bool $shouldRegisterNavigation = false;

    /** The shop opens on the pictures, as a gallery. */
    public string $display = 'gallery';
}
