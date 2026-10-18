<?php

namespace App\Modules\Retrieval\Sources;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Contracts\DocumentNotes;
use App\Modules\Retrieval\Contracts\DocumentSource;
use App\Modules\Retrieval\Contracts\SourceDocument;
use App\Modules\Retrieval\Enums\ChunkSource;
use App\Modules\Retrieval\Models\RetrievalImage;
use Throwable;

/**
 * Every product the store still publishes, as the text that says what it is: title, brand,
 * categories, descriptions, attributes, spec fields and tags; then what the picture shows and
 * what other modules learned and approved (DocumentNotes: type, use, details, highlights).
 *
 * Price and stock are left out on purpose. They change every day and say nothing about what a
 * product is, and leaving them out keeps a product's text — and so its vector — unchanged
 * until the product itself changes.
 */
final class ProductDocuments implements DocumentSource
{
    private const BATCH = 200;

    public function key(): string
    {
        return ChunkSource::Product->value;
    }

    public function documents(string $shopId): iterable
    {
        foreach (CatalogProduct::query()->active()->orderBy('id')->lazy(self::BATCH)->chunk(self::BATCH) as $products) {
            $ids = $products->map(fn (CatalogProduct $p): string => (string) $p->id)->values()->all();
            $notes = self::notes(ChunkSource::Product, $ids);
            $captions = RetrievalImage::query()->whereIn('product_id', $ids)->whereNotNull('caption')->pluck('caption', 'product_id');

            foreach ($products as $product) {
                $extra = $notes[(string) $product->id] ?? [];

                if (($caption = trim((string) ($captions[$product->id] ?? ''))) !== '') {
                    array_unshift($extra, 'Picture: '.$caption);
                }

                yield new SourceDocument($product->id, $product->external_id, $product->title, self::text($product, $extra));
            }
        }
    }

    /**
     * The notes every DocumentNotes gives for these ids, in a fixed order.
     *
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    public static function notes(ChunkSource $source, array $ids): array
    {
        $all = [];

        foreach (app()->tagged(DocumentNotes::NOTES_TAG) as $notes) {
            if (! $notes instanceof DocumentNotes) {
                continue;
            }

            try {
                foreach ($notes->notes($source->value, $ids) as $id => $lines) {
                    $all[(string) $id] = [...($all[(string) $id] ?? []), ...$lines];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $all;
    }

    /** @param  list<string>  $extra  lines learned beyond the store's own text */
    public static function text(CatalogProduct $product, array $extra = []): string
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

        // Its measurements, found by size: "מדף 80 ס״מ" is near a shelf 80 cm long.
        $measured = $product->measurements();
        $lines[] = $measured['size'] === null ? '' : 'Dimensions: '.$measured['size'];
        $lines[] = $measured['weight'] === null ? '' : 'Weight: '.$measured['weight'];

        $tags = array_filter(array_map('strval', (array) ($product->payload['tags'] ?? [])));

        if ($tags !== []) {
            $lines[] = 'Tags: '.implode(', ', $tags);
        }

        array_push($lines, ...$extra);

        return implode("\n", array_values(array_unique(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''))));
    }
}
