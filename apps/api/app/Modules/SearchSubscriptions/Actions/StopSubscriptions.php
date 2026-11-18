<?php

namespace App\Modules\SearchSubscriptions\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;

/**
 * One tap in an email, no login: "Stop this search" stops one; "Unsubscribe from all" (and a mail client's own
 * unsubscribe button) stops every search of the address in the store.
 */
final class StopSubscriptions
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function one(SearchSubscription $subscription): void
    {
        $this->tenant->run($subscription->shop_id, fn () => $subscription->stop('one'));
    }

    /** @return int how many were stopped */
    public function all(string $shopId, string $emailHash): int
    {
        return $this->tenant->run($shopId, function () use ($emailHash): int {
            $open = SearchSubscription::query()->where('email_hash', $emailHash)->where('status', '!=', SearchSubscription::STOPPED)->get();
            $open->each(fn (SearchSubscription $s) => $s->stop('all'));

            return $open->count();
        });
    }
}
