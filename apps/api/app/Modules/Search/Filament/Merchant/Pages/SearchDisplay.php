<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Search\Filament\Operator\Pages\SearchDisplay as OperatorSearchDisplay;
use UnitEnum;

/** How the search shows in this shop: the same screen the operator uses, the shop fixed. */
final class SearchDisplay extends OperatorSearchDisplay
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 20;

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still loads the values.
        $this->lockShopToTenant();
        parent::mount();
    }

    /** Out of the settings menu: its parts live on the new settings pages; the address still opens it. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
