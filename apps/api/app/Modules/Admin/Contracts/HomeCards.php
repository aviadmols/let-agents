<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * What a module brings to the cards at the top of the shop's home. A module tags its class
 * 'admin.home_cards'; the home asks every tagged source, inside the shop, and lays them out by order.
 */
interface HomeCards
{
    public const CARDS_TAG = 'admin.home_cards';

    /** @return list<HomeCard> */
    public function cards(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array;
}
