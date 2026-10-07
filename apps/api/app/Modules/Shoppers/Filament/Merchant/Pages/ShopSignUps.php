<?php

namespace App\Modules\Shoppers\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Shoppers\Filament\Operator\Pages\ShopSignUps as OperatorShopSignUps;
use UnitEnum;

/**
 * The shop's own sign-ups, with the contacts people left it. The same screen the operator uses,
 * with the shop fixed to the one in the address — which matters most here, where the rows are
 * people's contact details.
 */
final class ShopSignUps extends OperatorShopSignUps
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 30;
}
