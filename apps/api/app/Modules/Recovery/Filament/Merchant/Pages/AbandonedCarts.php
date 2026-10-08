<?php

namespace App\Modules\Recovery\Filament\Merchant\Pages;

use App\Core\Tenancy\LocksShopToPanelTenant;
use App\Modules\Recovery\Filament\Operator\Pages\AbandonedCarts as OperatorAbandonedCarts;

/**
 * The shop's abandoned carts and their reports. The same screen the operator uses, with the shop
 * fixed to the one in the address.
 */
final class AbandonedCarts extends OperatorAbandonedCarts
{
    use LocksShopToPanelTenant;
}
