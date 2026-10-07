<?php

namespace App\Modules\Improvement\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Improvement\Filament\Operator\Pages\SiteSuggestions as OperatorSiteSuggestions;
use UnitEnum;

/**
 * What the daily review proposes for this shop. The same screen the operator uses, with the shop
 * fixed to the one in the address.
 */
final class SiteSuggestions extends OperatorSiteSuggestions
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 20;
}
