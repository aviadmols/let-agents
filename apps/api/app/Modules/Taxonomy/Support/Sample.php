<?php

namespace App\Modules\Taxonomy\Support;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Collection;

/**
 * The products and posts a store's tag bank is built from: not at random, but across what the store
 * holds. Each product type first, then each of the store's deepest categories in turn, the largest
 * first, a few each, spread through the category rather than its first products; then posts across
 * their kinds. Code only; call inside the shop's tenant.
 */
final class Sample
{
    /** @return Collection<int, CatalogProduct> */
    public static function products(int $max, int $perGroup): Collection
    {
        $products = CatalogProduct::query()->whereNull('removed_at')->with('categories:id,name,depth')->orderBy('id')->get();

        if ($products->count() <= $max) {
            return $products;
        }

        // by the deepest category each is in: "ספריית שיבולת" rather than "ספרים"
        $groups = $products->groupBy(fn (CatalogProduct $p): string => (string) ($p->categories->sortByDesc('depth')->first()?->name ?? '—'))
            ->sortByDesc(fn (Collection $g): int => $g->count());
        $taken = [];

        // one of each product type (a bundle, a subscription, a variable product)
        foreach ($products->groupBy('type') as $ofType) {
            $taken[$ofType->first()->id] = $ofType->first();
        }

        // a round takes one of each group, spread through it: its first, then a third in, then two thirds…
        for ($round = 0; count($taken) < $max && $round < $perGroup; $round++) {
            foreach ($groups as $group) {
                $pick = $group->values()->get((int) floor($round * $group->count() / $perGroup));
                if ($pick !== null && ! isset($taken[$pick->id])) {
                    $taken[$pick->id] = $pick;
                }
                if (count($taken) >= $max) {
                    break;
                }
            }
        }

        return collect(array_values($taken));
    }

    /** @return Collection<int, CatalogContent> */
    public static function posts(int $max): Collection
    {
        $posts = CatalogContent::query()->whereNull('removed_at')->orderBy('id')->get();
        $byType = $posts->groupBy('type');
        $taken = [];

        $rounds = max(1, (int) ceil($max / max(1, $byType->count())));
        for ($round = 0; count($taken) < $max && $round < $rounds; $round++) {
            foreach ($byType as $ofType) {
                $pick = $ofType->values()->get((int) floor($round * $ofType->count() / $rounds));
                if ($pick !== null && ! isset($taken[$pick->id]) && count($taken) < $max) {
                    $taken[$pick->id] = $pick;
                }
            }
        }

        return collect(array_values($taken));
    }
}
