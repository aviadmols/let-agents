<?php

namespace App\Modules\Catalog\Listeners;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Events\CatalogUpdated;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Events\StoreAddressMoved;

/**
 * When the store moves, every saved link on the old host (a product, its picture, a page, a
 * category) points at the new one at once, without waiting for the next sync. Links on other
 * hosts (a CDN) stay. "www." on the old host is the same site.
 */
final class MoveCatalogLinks
{
    public function handle(StoreAddressMoved $event): void
    {
        $bare = fn (string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        $from = $bare(strtolower($event->from));
        $to = strtolower($event->to);

        if ($from === '' || $to === '' || $from === $bare($to)) {
            return;
        }

        $move = function (?string $url) use ($from, $to, $bare): ?string {
            $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

            if ($host === '' || $bare($host) !== $from) {
                return $url;
            }

            return (string) preg_replace('~^(\w+://)[^/:?#]+~u', '${1}'.$to, (string) $url, 1);
        };

        app(TenantContext::class)->run($event->shopId, function () use ($move, $from): void {
            foreach ([CatalogProduct::class, CatalogContent::class, CatalogCategory::class] as $model) {
                $model::query()
                    ->where(fn ($q) => $q->where('url', 'like', '%'.$from.'%')->orWhere('image_url', 'like', '%'.$from.'%'))
                    ->chunkById(500, function ($rows) use ($move): void {
                        foreach ($rows as $row) {
                            $row->url = $move($row->url);
                            $row->image_url = $move($row->image_url);

                            if ($row->isDirty()) {
                                $row->save();
                            }
                        }
                    });
            }
        });

        event(new CatalogUpdated($event->shopId));
    }
}
