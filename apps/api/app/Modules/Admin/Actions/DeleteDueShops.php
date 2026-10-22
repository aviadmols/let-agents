<?php

namespace App\Modules\Admin\Actions;

use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Support\ShopLeavingNotices;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * Every night: the shops whose deletion day came are deleted with everything they hold. Each table goes by its
 * foreign key, and Shop::deleting / Shop::deleted clean what has none (the kernel's tables, Try-On's files).
 * The people stay; one left with no shop can no longer open the merchant panel.
 */
final class DeleteDueShops
{
    /** @return int how many shops were deleted */
    public function handle(): int
    {
        $deleted = 0;

        Shop::query()->whereNotNull('deletion_due_at')->where('deletion_due_at', '<=', now())->orderBy('deletion_due_at')->each(function (Shop $shop) use (&$deleted): void {
            $copy = $shop->replicate();
            $shop->delete();
            Log::info('A shop was deleted on its owner\'s request', ['shop' => $shop->id, 'asked_by' => $shop->deletion_asked_by]);
            ShopLeavingNotices::team($copy, TeamNoticeMail::DELETED, $shop->deletion_asked_by, false);
            $deleted++;
        });

        return $deleted;
    }
}
