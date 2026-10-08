<?php

namespace App\Modules\Shopify\Events;

/**
 * Shopify passed on a shopper's privacy request: "redact" to erase what is kept about them in
 * this shop, "report" to say what is kept. Modules that keep shopper data by email listen.
 */
final class ShopperDataRequested
{
    public function __construct(
        public readonly string $shopId,
        public readonly string $kind,
        public readonly string $email,
    ) {}
}
