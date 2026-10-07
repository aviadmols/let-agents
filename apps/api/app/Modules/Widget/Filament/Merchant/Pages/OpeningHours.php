<?php

namespace App\Modules\Widget\Filament\Merchant\Pages;

use App\Modules\Widget\Filament\Operator\Pages\OpeningHours as OperatorOpeningHours;
use Filament\Facades\Filament;
use UnitEnum;

/**
 * The same week, for the shop whose panel this is. The shop comes from the address and is never
 * a choice here, so there is no state a request could talk this screen out of.
 */
final class OpeningHours extends OperatorOpeningHours
{
    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 10;

    public function shopId(): ?string
    {
        $shop = Filament::getTenant();

        return $shop === null ? null : (string) $shop->getKey();
    }
}
