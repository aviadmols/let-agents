<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * A platform that bills a shop for its plan (Shopify, on the merchant's Shopify invoice) ends that bill when
 * the owner ends the plan or asks for the shop to be deleted (Settings · Privacy and account). A module tags
 * its class 'admin.subscription_cancellers'; every tagged source that bills the shop is asked to cancel.
 * A shop no platform bills (a WordPress store, billed by the team) is cancelled by the team, who are told.
 */
interface CancelsSubscription
{
    public const TAG = 'admin.subscription_cancellers';

    /** Whether this platform bills the shop now. */
    public function bills(Shop $shop): bool;

    /** Whether cancelling keeps the shop working to the end of a period already paid (or a free trial), not ending it at once. */
    public function keepsPaidPeriod(Shop $shop): bool;

    /**
     * Ends the bill now, so the shop is never billed again. Throws when the platform refuses: the plan is then not ended.
     *
     * @return CarbonInterface|null when the period already paid (or the free trial) ends; null: nothing runs on
     */
    public function cancel(Shop $shop): ?CarbonInterface;
}
