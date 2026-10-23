<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Contracts\CancelsSubscription;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\ShopLeavingNotices;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * The owner ends the plan: the platform that bills the shop stops now (Shopify), so it is never billed again, and
 * the team is told; a store no platform bills is cancelled by the team by hand. A plan paid ahead (or in its free
 * trial) keeps the shop working until that period ends (Shop::plan_ends_at; EndDuePlans pauses it then); any
 * other shop pauses at once (the search leaves the site). Either way it keeps all it holds. Asking for the shop to
 * be deleted ends it at once ($atOnce). Choosing a plan again brings the shop back (Shopify's billing, or the team).
 */
final class EndShopPlan
{
    /**
     * Throws when a platform refuses to stop billing: then nothing changes.
     *
     * @return bool whether a platform billed the shop (false: the team ends the bill by hand)
     */
    public function handle(Shop $shop, User $by, bool $tellTeam = true, bool $atOnce = false): bool
    {
        $billed = false;
        $ends = null;

        foreach ($this->platforms() as $platform) {
            if ($platform->bills($shop)) {
                $until = $platform->cancel($shop);
                $billed = true;
                $ends = $until !== null && ($ends === null || $until->greaterThan($ends)) ? $until : $ends;
            }
        }

        $shop->refresh();
        $ends = $atOnce ? null : ($ends ?? $shop->planRunsUntil());
        $runs = $ends instanceof CarbonInterface && $ends->isFuture();
        $shop->forceFill([
            'cancelled_at' => $shop->cancelled_at ?? now(),
            'plan_ends_at' => $runs ? $ends : null,
        ] + ($runs || $shop->status === ShopStatus::Disabled ? [] : ['status' => ShopStatus::Paused]))->save();
        Log::info('A shop\'s owner ended its plan', ['shop' => $shop->id, 'by' => $by->id, 'billed' => $billed, 'runs_until' => $runs ? $ends->toIso8601String() : null]);

        if ($tellTeam) {
            ShopLeavingNotices::team($shop, TeamNoticeMail::PLAN_ENDED, (string) $by->email, ! $billed);
        }

        return $billed;
    }

    /** Whether ending the plan now keeps the shop working to the end of a period already paid, or ends it at once. */
    public function keepsPaidPeriod(Shop $shop): bool
    {
        foreach ($this->platforms() as $platform) {
            if ($platform->bills($shop) && $platform->keepsPaidPeriod($shop)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CancelsSubscription> */
    private function platforms(): array
    {
        $platforms = [];

        foreach (app()->tagged(CancelsSubscription::TAG) as $platform) {
            if ($platform instanceof CancelsSubscription) {
                $platforms[] = $platform;
            }
        }

        return $platforms;
    }
}
