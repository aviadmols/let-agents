<?php

namespace App\Modules\Admin\Contracts;

use App\Modules\Tenancy\Models\Shop;

/**
 * What Lets understood about the store's products, for the Store knowledge screen (the designer's redesign, 11.2026):
 * the counts, a row of products to pick from (a dot on one that misses a detail), and one product with what was
 * understood of it and where from. One module answers it (Enrichment). Plain arrays; no model.
 */
interface KnowledgeProducts
{
    public const TAG = 'admin.knowledge_products';

    /** @return array{products: int, photos: int, pages: int, posts: int, read_at: string|null} read_at: the last full reading, ISO 8601 */
    public function numbers(Shop $shop): array;

    /**
     * Products to pick from, the ones missing a detail first. search: words of a title or a SKU, or empty.
     *
     * @return list<array{id: string, title: string, picture: string|null, missing: bool}>
     */
    public function strip(Shop $shop, string $search, int $limit): array;

    /**
     * One product. tags: what was understood, each with where from (title, page, photo, team or understood); missing:
     * the details its kind of product should have and it lacks; saw: what the photo shows, in words; fix: where the
     * team corrects it.
     *
     * @return array{id: string, title: string, picture: string|null, price: string|null, url: string|null, tags: list<array{label: string, from: string}>, missing: list<string>, saw: string|null, fix: string|null}|null
     */
    public function product(Shop $shop, string $id): ?array;
}
