<?php

namespace App\Modules\Retrieval\Candidates;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\Candidate;
use App\Modules\Retrieval\Contracts\CandidateSource;
use App\Modules\Retrieval\Contracts\SemanticSearch;
use App\Modules\Retrieval\Enums\ChunkSource;

/**
 * Products whose text means nearly the same as this one's, from the index: the natural
 * alternatives, and now and then an accessory that describes itself by what it fits.
 */
final class SimilarProducts implements CandidateSource
{
    public function __construct(private readonly SemanticSearch $search) {}

    public function key(): string
    {
        return 'similar';
    }

    public function prepare(string $shopId): void {}

    public function candidates(CatalogProduct $product, int $limit): array
    {
        $source = ChunkSource::Product->value;

        return array_map(
            fn (array $hit): Candidate => new Candidate($hit['source_id'], $this->key(), $hit['similarity'], ['similarity' => $hit['similarity']]),
            $this->search->similarTo($source, $product->id, [$source], $limit),
        );
    }
}
