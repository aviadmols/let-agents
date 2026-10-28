<?php

namespace App\Modules\Retrieval\Listeners;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogLinksMoved;
use App\Modules\Retrieval\Actions\CaptionImages;
use App\Modules\Retrieval\Models\RetrievalGalleryImage;
use App\Modules\Retrieval\Models\RetrievalImage;

/**
 * The store moved: a picture at the new address is the same picture. Its vector and its caption are kept under its new
 * link, so the rebuild that follows reads none of them again (a picture's link is its fingerprint: BuildImageIndex,
 * CaptionImages).
 */
final class MovePictureLinks
{
    public function handle(CatalogLinksMoved $event): void
    {
        app(TenantContext::class)->run($event->shopId, function () use ($event): void {
            $model = trim((string) Settings::get('retrieval.caption_model'));
            $language = CaptionImages::language($event->shopId);

            foreach ([RetrievalImage::class, RetrievalGalleryImage::class] as $kind) {
                $kind::query()->where('image_url', 'like', '%'.$event->from.'%')->chunkById(500, function ($rows) use ($event, $model, $language): void {
                    foreach ($rows as $row) {
                        $url = (string) $event->link($row->image_url);

                        if ($url === $row->image_url) {
                            continue;
                        }

                        $hash = hash('sha256', $url);

                        if ($row instanceof RetrievalImage && $row->caption_key !== null && $row->caption_key === CaptionImages::key($row->url_hash, $model, $language)) {
                            $row->caption_key = CaptionImages::key($hash, $model, $language);
                        }

                        $row->image_url = $url;
                        $row->url_hash = $hash;
                        $row->save();
                    }
                });
            }
        });
    }
}
