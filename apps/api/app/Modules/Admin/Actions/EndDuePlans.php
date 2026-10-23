<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * Every hour: the shops whose cancelled plan reached the end of the period already paid (Shop::plan_ends_at, set by
 * EndShopPlan) pause, the search leaving the site; all they hold is kept. The end is cleared once applied, so a shop
 * the team brings back by hand is never paused again by an old date.
 */
final class EndDuePlans
{
    /** @return int how many shops were paused */
    public function handle(): int
    {
        $paused = 0;

        Shop::query()->whereNotNull('plan_ends_at')->where('plan_ends_at', '<=', now())->orderBy('plan_ends_at')->each(function (Shop $shop) use (&$paused): void {
            $pause = $shop->status !== ShopStatus::Disabled;
            $shop->forceFill(['plan_ends_at' => null] + ($pause ? ['status' => ShopStatus::Paused] : []))->save();
            Log::info('A cancelled plan reached the end of its paid period', ['shop' => $shop->id]);
            $paused += $pause ? 1 : 0;
        });

        return $paused;
    }
}
