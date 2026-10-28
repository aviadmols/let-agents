<?php

namespace App\Modules\Retrieval\Support;

use App\Core\Facades\Features;
use App\Modules\Admin\Contracts\ScanProgress;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalChunk;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Tenancy\Models\Shop;

/**
 * How far the pictures and the pages are in the scan, counted from what is stored. Pictures: each product's main
 * picture looked at under the current preparation and model (one that could not be read counts as gone through),
 * then described, where descriptions are on. Pages: the pieces of text read by meaning in this scan, of those still
 * waiting. Inside the shop.
 */
final class RetrievalScanProgress implements ScanProgress
{
    public function scanProgress(Shop $shop, array $began): array
    {
        $progress = [];

        if (Features::enabled('retrieval.image_index', $shop->id)) {
            $pictures = CatalogProduct::query()->active()->whereNotNull('image_url')->where('image_url', 'like', 'http%')->count();
            $model = ModelChoice::for('image')->model;
            $looked = RetrievalImage::query()->where(fn ($q) => $q->whereNotNull('error')
                ->orWhere(fn ($q) => $q->whereNotNull('embedded_at')->where('prep', PictureTrim::VERSION)->where('embedding_model', $model)))->count();
            $done = min($looked, $pictures);
            $total = $pictures;
            // described after: as many again, those described since the step began
            if (Features::enabled('retrieval.image_captions', $shop->id)) {
                $total += $pictures;
                $done += isset($began['pictures']) ? min($pictures, RetrievalImage::query()->where('captioned_at', '>=', $began['pictures'])->count()) : 0;
            }
            $progress['pictures'] = ['done' => $done, 'total' => $total];
        }

        // pieces of text read by meaning in this scan, of those read and those still waiting
        if (isset($began['pages'])) {
            $model = EmbeddingChoice::forShop($shop->id)->choice->model;
            $read = RetrievalChunk::query()->where('embedded_at', '>=', $began['pages'])->count();
            $waiting = RetrievalChunk::query()->where(fn ($q) => $q->whereNull('embedding_model')->orWhere('embedding_model', '!=', $model))->count();
            $progress['pages'] = ['done' => $read, 'total' => $read + $waiting > 0 ? $read + $waiting : null];
        }

        return $progress;
    }
}
