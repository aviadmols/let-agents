<?php

namespace App\Modules\Admin\Contracts;

use Illuminate\Http\Request;

/**
 * A platform whose own admin shows the shop's panel inside it (the Shopify admin, in a frame). There the platform
 * signed the person in, so the panel offers no way to sign out and no password to change: either would leave them on
 * an email and password form inside the platform's admin. A module tags its class 'admin.panel_hosts'; the panel is
 * hosted when any tagged host says so.
 */
interface PanelHost
{
    public const TAG = 'admin.panel_hosts';

    /** Whether this request is the panel shown inside this platform's admin. */
    public function hosts(Request $request): bool;
}
