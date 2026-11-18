<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalImage;

/**
 * What a product's own picture shows, as its reader described it at the scan (RetrievalImage::caption): how the piece is
 * built and looks, which its page often does not say. Rocks, 18.11: "מה הכוונה שני חלקים?" under earrings whose page says
 * only "שני חלקים, חלק אחורי מניפה", while their picture's description said which part is the stud and which the fan; the
 * writer was never given it, and said the phrase again in other words. Nothing is read from a model here.
 */
final class PictureOf
{
    private const MAX_CHARS = 600;

    public static function product(CatalogProduct $product): ?string
    {
        $caption = RetrievalImage::query()->where('product_id', $product->id)->whereNotNull('caption')->orderBy('id')->value('caption');
        $caption = trim((string) preg_replace('/\s+/u', ' ', (string) $caption));

        return $caption === '' ? null : mb_substr($caption, 0, self::MAX_CHARS);
    }
}
