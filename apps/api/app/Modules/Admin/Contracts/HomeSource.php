<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * What a module brings to the shop's home and notices. A module tags its class 'admin.home'; the
 * home asks every tagged source, inside the shop, and shows what they answer. A source answers []
 * for what it has nothing to say about.
 */
interface HomeSource
{
    /** The tag a module gives its source in its service provider. */
    public const TAG = 'admin.home';

    /** @return list<HomeStatus> */
    public function statuses(Shop $shop): array;

    /** @return list<ShopNotice> */
    public function notices(Shop $shop): array;

    /** @return list<ModuleFigure> */
    public function figures(Shop $shop, CarbonInterface $since, CarbonInterface $until, CarbonInterface $before): array;
}
