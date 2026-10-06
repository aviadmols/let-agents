<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * A product code found worth offering the matching model for another product, and the evidence:
 * signals are plain numbers (orders together, lift, similarity), shown to the model and kept.
 */
final class Candidate
{
    /** @param array<string, int|float|string> $signals */
    public function __construct(
        public readonly string $productId,
        public readonly string $source,
        public readonly float $strength,
        public readonly array $signals = [],
    ) {}
}
