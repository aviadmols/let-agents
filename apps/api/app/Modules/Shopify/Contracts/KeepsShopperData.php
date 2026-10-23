<?php

namespace App\Modules\Shopify\Contracts;

use App\Modules\Shopify\Events\ShopperDataRequested;

/**
 * A module that keeps something about a shopper says what, when the shopper asks the store for it (Shopify's
 * customers/data_request). It finds them as its listener for erasing does (the contact's code from the email or
 * the phone, the Shopify customer id). A module tags its class 'shopify.shopper_data'; every tagged source is
 * asked inside the shop, and the answers make the one report the store's owners download and send on.
 */
interface KeepsShopperData
{
    public const TAG = 'shopify.shopper_data';

    /**
     * What this module keeps about the shopper the request names: a section's name => its rows, each row plain
     * values (text, numbers, dates as ISO 8601), as written, not encrypted. [] when it keeps nothing about them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function about(ShopperDataRequested $request): array;
}
