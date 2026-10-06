<?php

namespace App\Modules\Ai\Contracts;

/** Vectors for a list of texts, in the same order, and what making them used. */
final class Embeddings
{
    /** @param list<list<float>> $vectors */
    public function __construct(
        public readonly array $vectors,
        public readonly int $inputTokens,
    ) {}

    /** The cost at a price per million input tokens. Embeddings have no output tokens. */
    public function costUsd(float $inputPerMillion): float
    {
        return $this->inputTokens * $inputPerMillion / 1_000_000;
    }

    public function dimensions(): int
    {
        return count($this->vectors[0] ?? []);
    }
}
