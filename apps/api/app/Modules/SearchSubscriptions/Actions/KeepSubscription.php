<?php

namespace App\Modules\SearchSubscriptions\Actions;

use App\Core\Facades\Settings;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;

/**
 * "Keep this search" in the reminder: another search_subscriptions.keep_days from its end (or from now, if it already
 * ended a moment ago: a search stopped as expired is kept again). Call inside the shop's tenant.
 */
final class KeepSubscription
{
    public function handle(SearchSubscription $subscription): bool
    {
        $expired = $subscription->status === SearchSubscription::STOPPED && $subscription->stop_reason === 'expired';
        if ($subscription->status !== SearchSubscription::ACTIVE && ! $expired) {
            return false;
        }
        $from = $subscription->expires_at !== null && $subscription->expires_at->isFuture() ? $subscription->expires_at : now();
        $subscription->forceFill([
            'status' => SearchSubscription::ACTIVE, 'stop_reason' => null, 'stopped_at' => null,
            'expires_at' => $from->copy()->addDays((int) Settings::get('search_subscriptions.keep_days')),
            'reminded_at' => null,
        ])->save();

        return true;
    }
}
