<?php

namespace App\Modules\Retrieval\Candidates;

use App\Core\Facades\Settings;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\Candidate;
use App\Modules\Retrieval\Contracts\CandidateSource;
use App\Modules\Retrieval\Contracts\SemanticSearch;

/**
 * Products whose main picture looks like this one's: the alternative a description cannot carry
 * (the cut of a shirt, a pattern, a shade). Found by comparing picture vectors, no model call.
 * Offers nothing until the shop's pictures are indexed (retrieval.image_index).
 */
final class LookAlike implements CandidateSource
{
    public function __construct(private readonly SemanticSearch $search) {}

    public function key(): string
    {
        return 'looks_alike';
    }

    public function prepare(string $shopId): void {}

    public function candidates(CatalogProduct $product, int $limit): array
    {
        $floor = (float) Settings::get('retrieval.min_look_alike');
        $found = [];

        foreach ($this->search->lookAlike($product->id, $limit) as $hit) {
            if ($hit['similarity'] >= $floor) {
                $found[] = new Candidate($hit['product_id'], $this->key(), $hit['similarity'], ['looks_alike' => $hit['similarity']]);
            }
        }

        return $found;
    }
}
