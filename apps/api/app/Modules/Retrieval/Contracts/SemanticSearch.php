<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * Finding by meaning in a shop's index, without a model call: it compares vectors the index
 * already holds. Callers run inside the shop's tenant context.
 */
interface SemanticSearch
{
    /**
     * What is nearest to one indexed thing, among the sources asked for.
     *
     * @param  list<string>  $sources  e.g. ['product'] or ['content']
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, similarity: float}> nearest first, one per source document
     */
    public function similarTo(string $source, string $sourceId, array $sources, int $limit): array;

    /** Whether this shop has vectors from the model the settings name now. */
    public function ready(): bool;
}
