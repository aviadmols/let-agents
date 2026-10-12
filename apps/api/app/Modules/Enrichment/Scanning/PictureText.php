<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Retrieval\Models\RetrievalImage;

/**
 * What a model saw in each product's picture, as the photo search keeps it (a sentence and its
 * words): read as the last and least sure source of a product's attributes, after the store's
 * fields, its title and its description.
 */
final class PictureText
{
    /**
     * @param  list<string>|null  $productIds  null for every product of the shop
     * @return array<string, array{caption: string, words: list<string>}> by product id
     */
    public static function for(?array $productIds = null): array
    {
        $out = [];
        RetrievalImage::query()->whereNotNull('caption')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->orderBy('id')->get(['id', 'product_id', 'caption', 'caption_words'])
            ->each(function (RetrievalImage $image) use (&$out): void {
                $words = $image->caption_words;
                $words = is_array($words) ? $words : (array) json_decode((string) $words, true);
                $out[(string) $image->product_id] ??= ['caption' => (string) $image->caption, 'words' => array_values(array_map('strval', $words))];
            });

        return $out;
    }

    /** @param  array{caption: string, words: list<string>}  $picture */
    public static function text(array $picture): string
    {
        return TextNormalizer::clean(trim($picture['caption']."\n".implode(', ', $picture['words'])));
    }
}
