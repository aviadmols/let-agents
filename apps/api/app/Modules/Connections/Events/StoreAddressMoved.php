<?php

namespace App\Modules\Connections\Events;

/**
 * A shop's store connection now reads the store at a new host: the site moved. Whatever kept the
 * old host in a link (products, pictures, pages) is moved with it.
 */
final class StoreAddressMoved
{
    public function __construct(
        public readonly string $shopId,
        public readonly string $from,
        public readonly string $to,
    ) {}
}
