<?php

namespace App\Modules\Shoppers\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Shoppers\Filament\Operator\Pages\ShopSignUps as OperatorShopSignUps;
use UnitEnum;

/**
 * The shop's own sign-ups, with the contacts people left it. The same screen the operator uses,
 * with the shop fixed to the one in the address — which matters most here, where the rows are
 * people's contact details.
 */
final class ShopSignUps extends OperatorShopSignUps
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'inbox';

    protected static ?int $navigationSort = 30;

    /** Nothing is approved here: the screen's hint about approving answers is not shown. */
    public static ?string $sectionHint = null;

    /** The screen's name, as the menu says it; the tab says which page. */
    public function getTitle(): string
    {
        return __('admin::panels.screens.inbox');
    }

    /** Off the menu: reached from where it belongs (the analytics tabs, or the operator). */
}
