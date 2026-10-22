<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Contracts\CancelsSubscription;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\ShopLeavingNotices;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * The owner ends the plan, at once: the platform that bills the shop stops (Shopify), the shop pauses (the search
 * leaves the site) and keeps all it holds, and the team is told; a store no platform bills is cancelled by the
 * team by hand. Choosing a plan again brings the shop back (Shopify's billing, or the team).
 */
final class EndShopPlan
{
    /**
     * Throws when a platform refuses to stop billing: then nothing changes.
     *
     * @return bool whether a platform billed the shop (false: the team ends the bill by hand)
     */
    public function handle(Shop $shop, User $by, bool $tellTeam = true): bool
    {
        $billed = false;

        foreach (app()->tagged(CancelsSubscription::TAG) as $platform) {
            if ($platform instanceof CancelsSubscription && $platform->bills($shop)) {
                $platform->cancel($shop);
                $billed = true;
            }
        }

        $shop->refresh();
        $shop->forceFill(['cancelled_at' => $shop->cancelled_at ?? now()] + ($shop->status === ShopStatus::Disabled ? [] : ['status' => ShopStatus::Paused]))->save();
        Log::info('A shop\'s owner ended its plan', ['shop' => $shop->id, 'by' => $by->id, 'billed' => $billed]);

        if ($tellTeam) {
            ShopLeavingNotices::team($shop, TeamNoticeMail::PLAN_ENDED, (string) $by->email, ! $billed);
        }

        return $billed;
    }
}
