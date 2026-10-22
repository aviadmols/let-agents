<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * What a module counted about the store, for the first-run screens ("we found your store", the scan).
 * A module tags its class 'admin.shop_facts'; every tagged source is asked inside the shop and the answers
 * are merged. A source leaves out what it does not know: a screen shows only what some module counted.
 *
 * Keys (each optional):
 *   products       int    products the store publishes
 *   pages          int    the store's pages and posts
 *   pictures_read  int    products whose main picture Lets has looked at
 *   pages_read     int    pages and posts Lets has read
 *   tags           int    details and tags learned about the products
 *   answers        int    answers ready for what shoppers ask
 *   searchable     int    products the store's search holds now
 *   seen           array{text: string, pages: int}  one thing most product pages say (shipping first), and on how many
 */
interface ShopFacts
{
    public const TAG = 'admin.shop_facts';

    /** @return array<string, mixed> */
    public function shopFacts(Shop $shop): array;
}
