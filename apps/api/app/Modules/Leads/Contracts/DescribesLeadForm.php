<?php

namespace App\Modules\Leads\Contracts;

/**
 * The shop's "leave details" form, for whoever draws it on the storefront (the search drawer's config,
 * the product page's bank): the fields it asks, in order, each required or not, its heading, its consent
 * wording and what it promises. The answers are sent to POST /api/v1/widget/{site}/lead, with the
 * question that had no answer, and checked there field by field.
 */
interface DescribesLeadForm
{
    /**
     * Null when the shop does not take details (shoppers.callbacks off) or has no form that can reach
     * anybody; the storefront then offers none.
     *
     * @return array{fields: list<array{key: string, type: string, label: string, required: bool}>, title: string, promise: string, consent: string}|null
     */
    public function forShop(string $shopId, string $locale): ?array;
}
