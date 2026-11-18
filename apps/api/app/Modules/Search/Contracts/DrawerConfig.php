<?php

namespace App\Modules\Search\Contracts;

/**
 * What another module adds to the drawer's config (the index's "config", SearchIndexController): a module tags its
 * class 'search.drawer_config', and its keys join the config the drawer reads when it opens. Keys only a module's own
 * part of the drawer reads (Search Subscriptions: "subscribe"); none of the Search module's own keys. Read on every
 * opening of the drawer, so code only: a lookup or two, no model, nothing counted.
 */
interface DrawerConfig
{
    public const TAG = 'search.drawer_config';

    /** @return array<string, mixed> the keys to add for this shop; none when the module has nothing for it */
    public function forDrawer(string $shopId): array;
}
