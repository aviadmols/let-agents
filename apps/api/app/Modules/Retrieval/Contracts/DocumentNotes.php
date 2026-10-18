<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * What another module learned about products or posts and wants their meaning vectors to say too:
 * what a product is, what it is for, its approved details and highlights. Built in code from what is
 * already decided, never by a model at index time.
 *
 * The index asks every class tagged `retrieval.document_notes`; a module adds notes with one class
 * and one tag. The lines must be stable for unchanged input (sorted, no dates), or every night would
 * re-embed the same products.
 */
interface DocumentNotes
{
    public const NOTES_TAG = 'retrieval.document_notes';

    /**
     * Runs inside the shop's tenant context.
     *
     * @param  string  $source  product or content (ChunkSource)
     * @param  list<string>  $ids  catalogue row ids
     * @return array<string, list<string>> lines by id; an id with nothing to add is left out
     */
    public function notes(string $source, array $ids): array;
}
