<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * One way of turning the shop's words into vectors, to be measured (EmbedderTrials): the index as it
 * stands, or a provider and model tried in memory. "As search" embeds the shopper's words as a search
 * query, the way the model's own driver does it for pictures (a model like gemini-embedding-2 is told
 * the task in front of the text); otherwise queries and pages are embedded alike, as the index does.
 */
final class EmbedderTrial
{
    public function __construct(
        public readonly string $name,
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly ?int $dimensions = null,
        public readonly float $usdPerMillion = 0.0,
        public readonly bool $asSearch = false,
        public readonly bool $index = false,
    ) {}

    /** The index as it stands: its stored vectors, the queries embedded with its own model. */
    public static function index(): self
    {
        return new self('index', index: true);
    }
}
