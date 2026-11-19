<?php

namespace App\Modules\SearchSubscriptions\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionProduct;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;

/**
 * A store that removed Let’s (its connection failed, as an uninstall leaves it) gets nothing sent at once (SendWeekly
 * skips it), and its subscribers' data is deleted search_subscriptions.forget_after_days later (daily, from the
 * scheduler). A store erased altogether (shop/redact) loses them with the store itself.
 */
final class ForgetRemovedStores
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return int the stores whose subscriptions were deleted */
    public function handle(): int
    {
        $days = (int) Settings::get('search_subscriptions.forget_after_days');
        $gone = $this->tenant->runUnscoped(fn () => StoreConnection::query()->where('status', ConnectionStatus::Failed)
            ->where('updated_at', '<', now()->subDays($days))
            ->whereIn('shop_id', SearchSubscription::query()->select('shop_id')->distinct())
            ->pluck('shop_id')->all());

        foreach ($gone as $shopId) {
            $this->tenant->run((string) $shopId, function (): void {
                SearchSubscriptionProduct::query()->delete();
                SearchSubscriptionSend::query()->delete();
                SearchSubscription::query()->delete();
            });
        }

        return count($gone);
    }
}
