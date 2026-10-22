<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

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

    /** Ends the bill at once. Throws when the platform refuses: the plan is then not ended. */
    public function cancel(Shop $shop): void;
}
