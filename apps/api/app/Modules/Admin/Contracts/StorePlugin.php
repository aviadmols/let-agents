<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * The store plugin (WordPress / WooCommerce) as the shop manager gets it from their own panel: the
 * installable zip, and a connection token the plugin is given in its settings. One module answers it.
 */
interface StorePlugin
{
    /**
     * The newest installable plugin zip shipped with this application, or null when none was bundled.
     *
     * @return array{path: string, filename: string, version: string}|null
     */
    public function package(): ?array;

    /**
     * A new connection token for the shop's plugin. Shown once: only the connection keeps it, and from
     * now on it is the one the store must hold (a token given before stops working).
     */
    public function issueToken(Shop $shop): string;

    /** Asks the store now whether it answers with the token; null when the shop has no connection to ask. */
    public function check(Shop $shop): ?bool;
}
