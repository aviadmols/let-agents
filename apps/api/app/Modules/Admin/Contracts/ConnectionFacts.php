<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * What a module knows about how the shop is connected, for "Connection and plan" under settings. A
 * module tags its class 'admin.connection_facts'; the page asks every tagged source inside the shop and
 * merges what they answer. A source leaves out what it does not know.
 *
 * Keys (each optional):
 *   connected       bool         the store answers: the plugin's token works, or the Shopify app is installed
 *   plugin_version  string       the store plugin's version, from its last answer
 *   app_installed   bool         a Shopify store: the app is installed
 *   last_sync       CarbonInterface  the catalogue was last read from the store
 *   last_call       CarbonInterface  the store last called Lets (the plugin) or was checked
 *   has_token       bool         a connection token exists for the store's plugin
 *   plan            string       the plan's name, in the shop manager's words
 *   renews          CarbonInterface  when the plan renews
 *   trial_ends      CarbonInterface  when the trial ends, while in one
 *   plans_url       string       where the merchant changes plan themselves (Shopify's billing), when they can
 */
interface ConnectionFacts
{
    public const TAG = 'admin.connection_facts';

    /** @return array<string, mixed> */
    public function facts(Shop $shop): array;
}
