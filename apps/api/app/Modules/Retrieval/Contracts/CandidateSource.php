<?php

namespace App\Modules\Retrieval\Contracts;

use App\Modules\Catalog\Models\CatalogProduct;

/**
 * One way code finds products that might go with a product. The matcher asks every source
 * tagged `retrieval.candidates`, merges what they found, and lets the model choose only from
 * that. A new signal — reviews, returns, a supplier's catalogue — is one more class and a tag.
 */
interface CandidateSource
{
    public function key(): string;

    /** Once per run, before any product: read what is shared across products (the orders). */
    public function prepare(string $shopId): void;

    /** @return list<Candidate> strongest first */
    public function candidates(CatalogProduct $product, int $limit): array;
}
