<?php

namespace App\Modules\Enrichment\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Enrichment\Filament\Operator\Pages\MissingInfo as OperatorMissingInfo;
use UnitEnum;

/**
 * What the shop's products do not say and a shopper needs to know, for its manager to fill in. The
 * screen the operator uses, with the shop fixed to the one in the address.
 */
final class MissingInfo extends OperatorMissingInfo
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'inbox';

    protected static ?int $navigationSort = 20;

    /** The screen's name, as the menu says it; the tab says which page. */
    public function getTitle(): string
    {
        return __('admin::panels.screens.inbox');
    }
}
