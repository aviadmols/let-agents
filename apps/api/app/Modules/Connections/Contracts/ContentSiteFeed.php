<?php

namespace App\Modules\Connections\Contracts;

use Generator;

/**
 * A WordPress site with no WooCommerce (a content site: tours, courses, events and articles about them), read as a
 * catalogue in the feed shape: what it offers as products, what it writes as articles, never its internal pages. One
 * reader for a demo and for a site connected with the plugin, so both read a content site the same way. Bound by the
 * module that implements it; a store with WooCommerce never reaches it.
 */
interface ContentSiteFeed
{
    /** @return list<array<string, mixed>> the categories its offered items are filed under */
    public function categories(string $siteUrl): array;

    /** @return Generator<int, list<array<string, mixed>>> what it offers, as product records */
    public function products(string $siteUrl): Generator;

    /** @return list<string> the content types it writes ("post"), none when it writes nothing */
    public function contentTypes(string $siteUrl): array;

    /** @return Generator<int, list<array<string, mixed>>> what it writes, as content records */
    public function content(string $siteUrl, string $type): Generator;

    /**
     * The shop's search set for a content site: no cart, each card "Read more", its articles after the second row,
     * found by what they say, and prices in its currency.
     */
    public function settle(string $shopId, string $siteUrl): void;
}
