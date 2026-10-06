<?php

namespace App\Modules\Retrieval\Sources;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Retrieval\Contracts\DocumentSource;
use App\Modules\Retrieval\Contracts\SourceDocument;
use App\Modules\Retrieval\Enums\ChunkSource;

/** Every page and post the store shares and still publishes: title, terms, excerpt and body. */
final class ContentDocuments implements DocumentSource
{
    public function key(): string
    {
        return ChunkSource::Content->value;
    }

    public function documents(string $shopId): iterable
    {
        foreach (CatalogContent::query()->active()->orderBy('id')->lazy(100) as $content) {
            $lines = [];

            foreach ((array) $content->terms as $taxonomy => $terms) {
                $terms = array_filter(array_map('strval', (array) $terms));

                if ($terms !== []) {
                    $lines[] = ucfirst((string) $taxonomy).': '.implode(', ', $terms);
                }
            }

            $lines[] = (string) $content->excerpt;
            $lines[] = (string) $content->body;

            yield new SourceDocument(
                $content->id,
                $content->external_id,
                $content->title,
                implode("\n", array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== '')),
            );
        }
    }
}
