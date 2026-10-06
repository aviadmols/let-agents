<?php

namespace App\Modules\Retrieval\Contracts;

/**
 * Something the index reads documents from. Built in code, never by a model.
 *
 * The index asks every source tagged `retrieval.sources` in the container, so a module adds a
 * source of its own with one class and one tag, and the panel's map shows it as a new lane.
 * A source returns everything it currently has; a document it stops returning is removed from
 * the index, which is only ever a copy.
 */
interface DocumentSource
{
    /** A short stable key, stored on every chunk: product, content, purchases. */
    public function key(): string;

    /**
     * Runs inside the shop's tenant context.
     *
     * @return iterable<SourceDocument>
     */
    public function documents(string $shopId): iterable;
}
