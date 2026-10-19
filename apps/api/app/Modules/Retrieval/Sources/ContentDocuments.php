<?php

namespace App\Modules\Retrieval\Sources;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Retrieval\Contracts\DocumentSource;
use App\Modules\Retrieval\Contracts\SourceDocument;
use App\Modules\Retrieval\Enums\ChunkSource;

/** Every page and post the store shares and still publishes: title, terms, excerpt, body and its takeaways. */
final class ContentDocuments implements DocumentSource
{
    public function key(): string
    {
        return ChunkSource::Content->value;
    }

    public function documents(string $shopId): iterable
    {
        foreach (CatalogContent::query()->active()->orderBy('id')->lazy(100)->chunk(100) as $batch) {
            // What the reading took from each post (its takeaways), after its own text.
            $notes = ProductDocuments::notes(ChunkSource::Content, $batch->map(fn (CatalogContent $c): string => (string) $c->id)->values()->all());

            foreach ($batch as $content) {
                yield $this->document($content, $notes[(string) $content->id] ?? []);
            }
        }
    }

    /** @param  list<string>  $notes */
    private function document(CatalogContent $content, array $notes): SourceDocument
    {
        $lines = [];

        foreach ((array) $content->terms as $taxonomy => $terms) {
            // A term as a word, or as the store sends it whole ({id, name}): its name.
            $terms = array_filter(array_map(fn ($term): string => is_array($term) ? (string) ($term['name'] ?? $term['title'] ?? '') : (is_scalar($term) ? (string) $term : ''), (array) $terms));

            if ($terms !== []) {
                $lines[] = ucfirst((string) $taxonomy).': '.implode(', ', $terms);
            }
        }

        $lines[] = (string) $content->excerpt;
        $lines[] = (string) $content->body;
        array_push($lines, ...$notes);

        return new SourceDocument(
            $content->id,
            $content->external_id,
            $content->title,
            implode("\n", array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== '')),
        );
    }
}
