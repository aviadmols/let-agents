<?php

namespace App\Modules\Ai\Contracts;

/** A model's JSON answer and what it used. */
final class ModelReply
{
    /** A cached prompt read back costs a tenth of its input price; written to the cache, a quarter more (5-minute cache). */
    private const CACHE_READ_SHARE = 0.1;

    private const CACHE_WRITE_SHARE = 1.25;

    /**
     * @param  array<string, mixed>  $data
     * @param  int  $cacheReadTokens  the prompt read from the provider's cache, outside $inputTokens
     * @param  int  $cacheWriteTokens  the prompt written to it, outside $inputTokens
     */
    public function __construct(
        public readonly array $data,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {}

    /** The cost at prices per million tokens, the cached prompt at its own share of the input price. */
    public function costUsd(float $inputPerMillion, float $outputPerMillion): float
    {
        return ($this->inputTokens * $inputPerMillion + $this->cacheReadTokens * $inputPerMillion * self::CACHE_READ_SHARE
            + $this->cacheWriteTokens * $inputPerMillion * self::CACHE_WRITE_SHARE + $this->outputTokens * $outputPerMillion) / 1_000_000;
    }
}
