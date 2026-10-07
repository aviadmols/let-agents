<?php

namespace App\Modules\Analytics\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Analytics\Filament\Operator\Pages\ShopAnalytics as OperatorShopAnalytics;
use UnitEnum;

/**
 * The shop's own report. The same screen the operator uses, with the shop fixed to the one in the
 * address: a merchant has no shop to pick and no way to name another.
 */
final class ShopAnalytics extends OperatorShopAnalytics
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 10;

    /** A shop owner sees what happened in the store, never what it cost to run the agents. */
    public function showsCosts(): bool
    {
        return false;
    }
}
