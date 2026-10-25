<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Search\Filament\Operator\Pages\SearchCheck as OperatorSearchCheck;
use UnitEnum;

/**
 * Search check in the shop's own panel: the same screen the operator uses, the shop fixed, and written for the
 * store's people: why each result is there and what to change, without scores, models or costs.
 */
final class SearchCheck extends OperatorSearchCheck
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'analytics';

    protected static ?int $navigationSort = 30;

    public function picksShop(): bool
    {
        return false;
    }

    public function deep(): bool
    {
        return false;
    }

    public function synonymsUrl(): ?string
    {
        return ShopSearches::getUrl();
    }
}
