<?php

namespace App\Modules\Catalog\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogLinksMoved;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Connections\Events\StoreAddressMoved;

/**
 * When the store moves, every saved link on the old host (a product, its pictures, a variant's picture, a page, a
 * category) points at the new one at once, without waiting for the next sync. Links on other hosts (a CDN) stay.
 * "www." on the old host is the same site, and a move to its "www." moves them too: a link through the store's
 * redirect is no link to the store (Sela Meir, 28.10: sellameir.com sends every link on to www.sellameir.com).
 */
final class MoveCatalogLinks
{
    public function handle(StoreAddressMoved $event): void
    {
        $moved = new CatalogLinksMoved($event->shopId, CatalogLinksMoved::bare(strtolower($event->from)), strtolower($event->to));

        if ($moved->from === '' || $moved->to === '' || strtolower($event->from) === $moved->to) {
            return;
        }

        app(TenantContext::class)->run($event->shopId, function () use ($moved): void {
            $like = '%'.$moved->from.'%';

            foreach ([CatalogProduct::class, CatalogContent::class, CatalogCategory::class] as $model) {
                $model::query()
                    ->where(fn ($q) => $q->where('url', 'like', $like)->orWhere('image_url', 'like', $like)
                        ->when($model === CatalogProduct::class, fn ($q) => $q->orWhereRaw('CAST(payload AS TEXT) LIKE ?', [$like])))
                    ->chunkById(500, function ($rows) use ($moved): void {
                        foreach ($rows as $row) {
                            $row->url = $moved->link($row->url);
                            $row->image_url = $moved->link($row->image_url);

                            if ($row instanceof CatalogProduct) {
                                $row->payload = self::payload((array) $row->payload, $moved);
                            }

                            if ($row->isDirty()) {
                                $row->save();
                            }
                        }
                    });
            }

            CatalogVariant::query()->where('image_url', 'like', $like)->chunkById(500, function ($rows) use ($moved): void {
                foreach ($rows as $row) {
                    $row->image_url = $moved->link($row->image_url);
                    $row->isDirty() && $row->save();
                }
            });
        });

        // What other modules kept by a link moves before the catalogue is rebuilt for the new address.
        event($moved);
        event(new CatalogUpdated($event->shopId));
    }

    /**
     * Each link the store's feed gave as a value of its own (a picture of the gallery), moved; a description's words
     * stay as the store wrote them.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private static function payload(array $payload, CatalogLinksMoved $moved): array
    {
        array_walk_recursive($payload, function (mixed &$value) use ($moved): void {
            if (is_string($value) && preg_match('~^https?://~i', $value) === 1) {
                $value = (string) $moved->link($value);
            }
        });

        return $payload;
    }
}
