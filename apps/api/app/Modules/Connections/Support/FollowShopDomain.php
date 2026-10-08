<?php

namespace App\Modules\Connections\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;

/**
 * When a shop moves to a new domain, its store connection moves with it: Let Agents reads the
 * store at the new address, and the next catalogue sync brings every product's new link.
 *
 * Only a connection that pointed at the old domain is moved, keeping its scheme. One that points
 * somewhere else on purpose (a staging copy, another host) is left as it is.
 */
final class FollowShopDomain
{
    public static function handle(Shop $shop): void
    {
        if (! $shop->wasChanged('domain')) {
            return;
        }

        $old = strtolower((string) $shop->getOriginal('domain'));
        $new = strtolower((string) $shop->domain);

        if ($old === '' || $new === '' || $old === $new) {
            return;
        }

        app(TenantContext::class)->runUnscoped(function () use ($shop, $old, $new): void {
            foreach (StoreConnection::query()->where('shop_id', $shop->id)->get() as $connection) {
                $parts = parse_url((string) $connection->site_url);
                $host = strtolower((string) ($parts['host'] ?? ''));

                if ($host !== $old) {
                    continue;
                }

                $connection->site_url = ($parts['scheme'] ?? 'https').'://'.$new.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
                $connection->save();
            }
        });
    }
}
