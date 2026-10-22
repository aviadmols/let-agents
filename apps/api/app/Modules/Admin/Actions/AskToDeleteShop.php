<?php

namespace App\Modules\Admin\Actions;

use App\Core\Facades\Settings;
use App\Modules\Admin\Mail\ShopLeavingMail;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\ShopLeavingNotices;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * The owner asks for the shop and everything it holds to be deleted. The plan ends and the shop pauses at once; it
 * is deleted admin.shop_deletion_days later (DeleteDueShops), unless an owner keeps it before then (KeepShop). The
 * owners get the date and the link that keeps it, and the team is told.
 */
final class AskToDeleteShop
{
    public function __construct(private readonly EndShopPlan $endPlan) {}

    /** The day the shop will be deleted. Asking again changes nothing. */
    public function handle(Shop $shop, User $by): CarbonInterface
    {
        if ($shop->leaving()) {
            return $shop->deletion_due_at;
        }

        $billed = $this->endPlan->handle($shop, $by, tellTeam: false);
        $shop->forceFill([
            'deletion_due_at' => now()->addDays((int) Settings::get('admin.shop_deletion_days')),
            'deletion_asked_by' => mb_substr((string) $by->email, 0, 190),
        ])->save();
        Log::info('A shop\'s owner asked for it to be deleted', ['shop' => $shop->id, 'by' => $by->id, 'due' => $shop->deletion_due_at->toIso8601String()]);

        ShopLeavingNotices::owners($shop, ShopLeavingMail::ASKED, (string) $by->name);
        ShopLeavingNotices::team($shop, TeamNoticeMail::DELETION_ASKED, (string) $by->email, ! $billed);

        return $shop->deletion_due_at;
    }
}
