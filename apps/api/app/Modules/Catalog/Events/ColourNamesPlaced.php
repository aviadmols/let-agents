<?php

namespace App\Modules\Catalog\Events;

/**
 * Some of a shop's own colour names were placed among the shades (CatalogColourName): the variants
 * named so are read again, and the search follows.
 */
final class ColourNamesPlaced
{
    /** @param list<string> $names the names as the store writes them */
    public function __construct(
        public readonly string $shopId,
        public readonly array $names,
    ) {}
}
