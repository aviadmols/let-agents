<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * The passages a question would get if the store's pages were cut into pieces of another size: for
 * measuring how the piece size changes what is found, never for answering a shopper.
 *
 * The pages and articles are cut again and embedded with the index's own model, in memory only;
 * the index itself (retrieval_chunks) is neither read for them nor written. Products keep their
 * stored pieces: they are short, and nearly always one piece at any size. Call inside the shop's
 * tenant; the embedding is accounted on a run of its own.
 */
interface ChunkExperiments
{
    /**
     * @param  list<string>  $sources  product, content
     * @return list<array{source: string, source_id: string, external_id: string|null, title: string, text: string, similarity: float}> nearest first, at most two pieces per document
     */
    public function near(string $shopId, string $text, int $chunkChars, array $sources, int $limit): array;

    /** What cutting and embedding the pages again cost so far, in USD. */
    public function spent(): float;
}
