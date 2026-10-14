<?php

namespace App\Modules\Catalog\Events;

/**
 * Some of a shop's products changed in its store a few minutes ago and were read now (new, edited,
 * or taken off the store). Lighter than CatalogUpdated: only what reads products follows (the search).
 */
final class CatalogProductsChanged
{
    /** @param list<string> $externalIds */
    public function __construct(
        public readonly string $shopId,
        public readonly array $externalIds,
    ) {}
}
