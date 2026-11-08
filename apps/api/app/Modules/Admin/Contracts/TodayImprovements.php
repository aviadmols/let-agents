<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * What a module brings to Today's "Waiting for you" and "You fixed it": the fixes waiting for the owner, the one tap
 * that approves one, and the fixes approved with what they did since. A module tags its class
 * 'admin.today_improvements'; Today asks every tagged source inside the shop and ranks the fixes by impact.
 */
interface TodayImprovements
{
    public const TAG = 'admin.today_improvements';

    /** @return list<TodayFix> */
    public function waiting(Shop $shop): array;

    /**
     * Approve a fix whose oneTap is true, as the owner. Null when the key is not this source's.
     *
     * @return string|null what changed, for the toast
     */
    public function approve(Shop $shop, string $key, ?int $userId): ?string;

    /** @return list<TodayWin> the latest approved first */
    public function wins(Shop $shop, int $limit): array;
}
