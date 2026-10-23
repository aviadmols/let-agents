<?php

namespace App\Modules\Shopify\Events;

/**
 * Shopify passed on a shopper's privacy request: "redact" to erase what is kept about them in
 * this shop, "report" to say what is kept. Modules that keep shopper data, by email, phone or the
 * Shopify customer id, listen to erase; to report, they answer through Contracts\KeepsShopperData.
 */
final class ShopperDataRequested
{
    public function __construct(
        public readonly string $shopId,
        public readonly string $kind,
        public readonly string $email,
        public readonly string $customerId = '',
        public readonly string $phone = '',
        /** Shopify's id of a report request (data_request.id): the same request sent again is answered once. */
        public readonly string $requestId = '',
    ) {}
}
