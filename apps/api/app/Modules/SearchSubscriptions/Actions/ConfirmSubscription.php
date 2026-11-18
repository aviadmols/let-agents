<?php

namespace App\Modules\SearchSubscriptions\Actions;

use App\Core\Facades\Settings;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;

/**
 * The shopper pressed "Confirm subscription" in the email: the search is active from now, and only products added from
 * now on are new to it. It runs for search_subscriptions.keep_days unless kept. A second press changes nothing. Call
 * inside the shop's tenant.
 */
final class ConfirmSubscription
{
    public function handle(SearchSubscription $subscription): bool
    {
        if ($subscription->status === SearchSubscription::ACTIVE) {
            return true;
        }
        if ($subscription->status !== SearchSubscription::PENDING) {
            return false;
        }
        $subscription->forceFill([
            'status' => SearchSubscription::ACTIVE,
            'confirmed_at' => now(),
            'expires_at' => now()->addDays((int) Settings::get('search_subscriptions.keep_days')),
        ])->save();

        return true;
    }
}
