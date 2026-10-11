<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Assistant\Filament\Operator\Pages\AboutStore as OperatorAboutStore;
use UnitEnum;

/** What the assistant knows about this shop: the same screen the operator uses, the shop fixed. */
final class AboutStore extends OperatorAboutStore
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'search';

    protected static ?int $navigationSort = 20;

    public function mount(): void
    {
        // The trait also declares mount(); this one wins, and the parent still loads the text.
        $this->lockShopToTenant();
        parent::mount();
    }
}
