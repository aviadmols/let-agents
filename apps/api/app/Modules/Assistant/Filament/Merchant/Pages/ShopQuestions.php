<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Assistant\Filament\Operator\Pages\ShopQuestions as OperatorShopQuestions;
use UnitEnum;

/**
 * What shoppers asked this shop, and the team's own answers. The same screen the operator uses,
 * with the shop fixed to the one in the address.
 */
final class ShopQuestions extends OperatorShopQuestions
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 10;
}
