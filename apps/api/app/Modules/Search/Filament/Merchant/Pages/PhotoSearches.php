<?php

namespace App\Modules\Search\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Search\Filament\Operator\Pages\PhotoSearches as OperatorPhotoSearches;
use UnitEnum;

/**
 * The photos shoppers searched this shop with, and what they got: the same screen the operator
 * uses, with the shop fixed to the one in the address, without the mechanics, and with the marks
 * the shop's own people are best placed to give.
 */
final class PhotoSearches extends OperatorPhotoSearches
{
    use LocksShopToPanelTenant;

    protected static string|UnitEnum|null $navigationGroup = 'inbox';

    protected static ?int $navigationSort = 40;

    /** Nothing is approved here: the screen's hint about approving answers is not shown. */
    public static ?string $sectionHint = null;

    /** The screen's name, as the menu says it; the tab says which page. */
    public function getTitle(): string
    {
        return __('admin::panels.screens.inbox');
    }
}
