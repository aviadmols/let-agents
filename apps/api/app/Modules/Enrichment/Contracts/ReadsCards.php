<?php

namespace App\Modules\Enrichment\Contracts;

/**
 * The cards every model reads: one product, one page or post, or the store, as one compact JSON
 * (the shape: Enrichment\Support\Cards). Read inside the shop (TenantContext). A product or page
 * with no card yet has one built at once, in code.
 */
interface ReadsCards
{
    /** @return array<string, mixed>|null by the product's internal id */
    public function product(string $productId): ?array;

    /**
     * @param  list<string>  $productIds  internal ids
     * @return array<string, array<string, mixed>> by internal id
     */
    public function products(array $productIds): array;

    /** @return array<string, mixed>|null by the page's or post's internal id */
    public function content(string $contentId): ?array;

    /** @return array<string, mixed>|null the store's own card: its promises and its policy pages */
    public function store(): ?array;
}
