<?php

namespace App\Modules\Ai\Contracts;

/**
 * A text embedding driver whose model embeds a search query apart from the documents it searches
 * (Voyage's input_type): the shopper's words are asked for as a query, the pages as documents. A
 * driver of words alone; ProviderEmbedder hands it the queries the live search embeds.
 */
interface QueryEmbeddingDriver extends EmbeddingDriver
{
    /**
     * @param  list<string>  $texts
     *
     * @throws ModelCallFailed
     */
    public function embedQueries(string $apiKey, string $model, array $texts, ?int $dimensions = null): Embeddings;
}
