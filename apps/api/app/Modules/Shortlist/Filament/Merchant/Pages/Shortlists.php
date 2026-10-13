<?php

namespace App\Modules\Shortlist\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Shortlist\Filament\Operator\Pages\Shortlists as OperatorShortlists;
use UnitEnum;

/** The shop's compare and wishlist: the same screen the operator uses, the shop fixed. */
final class Shortlists extends OperatorShortlists
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 40;

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still loads the values.
        $this->lockShopToTenant();
        parent::mount();
    }
}
