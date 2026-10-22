<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Mail\ShopLeavingMail;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\ShopLeavingNotices;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * An owner keeps the shop before its deletion day: nothing is deleted. Its plan stays ended, so the shop stays
 * paused until a plan is chosen again (Shopify's billing, or the team).
 */
final class KeepShop
{
    public function handle(Shop $shop, User $by): void
    {
        if (! $shop->leaving()) {
            return;
        }

        $shop->forceFill(['deletion_due_at' => null, 'deletion_asked_by' => null])->save();
        Log::info('A shop\'s owner kept it', ['shop' => $shop->id, 'by' => $by->id]);

        ShopLeavingNotices::owners($shop, ShopLeavingMail::KEPT, (string) $by->name);
        ShopLeavingNotices::team($shop, TeamNoticeMail::KEPT, (string) $by->email, false);
    }
}
