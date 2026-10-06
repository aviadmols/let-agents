<?php

namespace App\Modules\Retrieval\Sources;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\DocumentSource;
use App\Modules\Retrieval\Contracts\SourceDocument;
use App\Modules\Retrieval\Enums\ChunkSource;

/**
 * Every product the store still publishes, as the text that says what it is: title, brand,
 * categories, descriptions, attributes, spec fields and tags.
 *
 * Price and stock are left out on purpose. They change every day and say nothing about what a
 * product is, and leaving them out keeps a product's text — and so its vector — unchanged
 * until the product itself changes.
 */
final class ProductDocuments implements DocumentSource
{
    public function key(): string
    {
        return ChunkSource::Product->value;
    }

    public function documents(string $shopId): iterable
    {
        foreach (CatalogProduct::query()->active()->orderBy('id')->lazy(200) as $product) {
            yield new SourceDocument($product->id, $product->external_id, $product->title, self::text($product));
        }
    }

    public static function text(CatalogProduct $product): string
    {
        $lines = [];
        $brands = array_filter(array_merge([(string) $product->brand], (array) ($product->payload['brands'] ?? [])));

        if ($brands !== []) {
            $lines[] = 'Brand: '.implode(', ', array_unique($brands));
        }

        foreach ($product->categoryPaths() as $path) {
            $lines[] = 'Category: '.implode(' > ', $path);
        }

        $lines[] = $product->shortDescription();
        $lines[] = $product->description();

        foreach ($product->storeAttributes() as $attribute) {
            $lines[] = $attribute['name'].': '.implode(', ', $attribute['values']);
        }

        array_push($lines, ...$product->specFields());

        $tags = array_filter(array_map('strval', (array) ($product->payload['tags'] ?? [])));

        if ($tags !== []) {
            $lines[] = 'Tags: '.implode(', ', $tags);
        }

        return implode("\n", array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
    }
}
