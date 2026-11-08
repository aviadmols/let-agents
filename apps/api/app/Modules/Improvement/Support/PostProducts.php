<?php

namespace App\Modules\Improvement\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Improvement\Models\ImprovementPost;
use Illuminate\Support\Collection;

/**
 * The store's products a post is about, as the team chooses them: the ones it links where it names them and shows as a
 * list at its end. Only products the store sells now; in the order chosen (the first gives the post its picture).
 * Callers are inside the shop's tenant. No model.
 */
final class PostProducts
{
    /**
     * The ids that are products the store sells now, once each, in their order, at most a post's number.
     *
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    public static function valid(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id): string => trim((string) $id), $ids), fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }
        $known = CatalogProduct::query()->active()->whereIn('external_id', $ids)->pluck('external_id')->map(fn ($id): string => (string) $id)->all();

        return array_slice(array_values(array_filter($ids, fn (string $id): bool => in_array($id, $known, true))), 0, ImprovementPost::MAX_PRODUCTS);
    }

    /**
     * The products themselves, in the post's order.
     *
     * @param  list<string>  $ids
     * @return Collection<int, CatalogProduct>
     */
    public static function models(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return CatalogProduct::query()->active()->whereIn('external_id', $ids)
            ->get(['id', 'external_id', 'title', 'url', 'image_url', 'sku', 'price'])
            ->sortBy(fn (CatalogProduct $p): int => (int) array_search((string) $p->external_id, $ids, true))
            ->values();
    }

    /**
     * Products to add, by a word of the title or the SKU, without the ones already in.
     *
     * @param  list<string>  $except
     * @return list<array{id: string, title: string, picture: string|null, sku: string|null}>
     */
    public static function find(string $words, array $except, int $limit = 6): array
    {
        $words = mb_strtolower(trim($words));
        if (mb_strlen($words) < 2) {
            return [];
        }
        $like = '%'.addcslashes($words, '%_\\').'%';

        return CatalogProduct::query()->active()
            ->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(sku) like ?', [$like]))
            ->when($except !== [], fn ($q) => $q->whereNotIn('external_id', $except))
            ->orderBy('title')->limit($limit)->get(['external_id', 'title', 'image_url', 'sku'])
            ->map(fn (CatalogProduct $p): array => ['id' => (string) $p->external_id, 'title' => (string) $p->title, 'picture' => $p->image_url ?: null, 'sku' => $p->sku ?: null])
            ->all();
    }
}
