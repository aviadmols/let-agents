<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Search\Filament\Operator\Pages\SearchAnalytics as OperatorSearchAnalytics;
use UnitEnum;

/** The site search of this shop, day after day: the same screen the operator uses, the shop fixed. */
final class SearchAnalytics extends OperatorSearchAnalytics
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 20;
}
