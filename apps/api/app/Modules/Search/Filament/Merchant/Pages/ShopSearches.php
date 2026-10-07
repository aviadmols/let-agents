<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Search\Filament\Operator\Pages\ShopSearches as OperatorShopSearches;
use UnitEnum;

/**
 * What shoppers searched in this shop, and its synonyms. The same screen the operator uses, with
 * the shop fixed to the one in the address.
 */
final class ShopSearches extends OperatorShopSearches
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'search';

    protected static ?int $navigationSort = 10;
}
