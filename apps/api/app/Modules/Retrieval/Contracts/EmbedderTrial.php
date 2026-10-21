<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * One way of turning the shop's words into vectors, to be measured (EmbedderTrials): the index as it
 * stands, or a provider and model tried in memory. Either way the shopper's words are embedded as the
 * live search embeds them, as a search query where the model takes one (gemini-embedding-2).
 */
final class EmbedderTrial
{
    public function __construct(
        public readonly string $name,
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly ?int $dimensions = null,
        public readonly float $usdPerMillion = 0.0,
        public readonly bool $index = false,
    ) {}

    /** The index as it stands: its stored vectors, the queries embedded with its own model. */
    public static function index(): self
    {
        return new self('index', index: true);
    }
}
