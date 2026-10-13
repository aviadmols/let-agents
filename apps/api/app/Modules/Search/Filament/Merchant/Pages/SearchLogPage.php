<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Search\Filament\Operator\Pages\SearchLogPage as OperatorSearchLogPage;
use UnitEnum;

/** Every search on this shop's site, with its visit: the same screen the operator uses, the shop fixed. */
final class SearchLogPage extends OperatorSearchLogPage
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 21;

    public function picksShop(): bool
    {
        return false;
    }
}
