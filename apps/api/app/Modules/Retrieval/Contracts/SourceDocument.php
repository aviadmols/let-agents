<?php

namespace App\Modules\Retrieval\Contracts;

/** One thing worth finding by meaning: a product, a post, what a product's orders say. */
final class SourceDocument
{
    public function __construct(
        public readonly string $sourceId,
        public readonly ?string $externalId,
        public readonly string $title,
        public readonly string $text,
    ) {}
}
