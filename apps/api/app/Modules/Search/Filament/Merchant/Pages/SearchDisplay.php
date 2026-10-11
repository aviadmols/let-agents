<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Search\Filament\Operator\Pages\SearchDisplay as OperatorSearchDisplay;
use UnitEnum;

/** How the search shows in this shop: the same screen the operator uses, the shop fixed. */
final class SearchDisplay extends OperatorSearchDisplay
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'search';

    protected static ?int $navigationSort = 5;

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still loads the values.
        $this->lockShopToTenant();
        parent::mount();
    }
}
