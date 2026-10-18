<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * What a module brings to the shop's timeline. A module tags its class 'admin.timeline'; the timeline
 * asks every tagged source, inside the shop, for what happened since a date, and sorts it all by time.
 */
interface TimelineSource
{
    /** The tag a module gives its source in its service provider. */
    public const TAG = 'admin.timeline';

    /** @return list<TimelineEntry> what happened in the shop since $since, in any order */
    public function entries(Shop $shop, CarbonInterface $since): array;
}
