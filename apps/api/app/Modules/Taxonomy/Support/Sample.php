<?php

namespace App\Modules\Taxonomy\Support;

use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Collection;

/**
 * The products and posts a store's tag bank is built from: not at random, but across what the store
 * holds. Each product type first, then each product's narrowest category in turn, the largest first,
 * one a round, spread through the category rather than its first products, until the sample is full;
 * then posts across their kinds. Code only; call inside the shop's tenant.
 */
final class Sample
{
    /** @return Collection<int, CatalogProduct> */
    public static function products(int $max, int $perGroup): Collection
    {
        $products = CatalogProduct::query()->whereNull('removed_at')->with('categories:id,name')->orderBy('id')->get();

        if ($products->count() <= $max) {
            return $products;
        }

        // by the narrowest category each is in, the one holding fewest of these products: "ספריית שיבולת"
        // rather than "ספרים", in a store whose categories all stand side by side as in one with a tree
        $sizes = $products->flatMap(fn (CatalogProduct $p) => $p->categories->pluck('id'))->countBy()->all();
        $groups = $products->groupBy(fn (CatalogProduct $p): string => (string) ($p->categories->sortBy(fn ($c): array => [$sizes[$c->id] ?? 0, $c->name])->first()?->name ?? '—'))
            ->sortByDesc(fn (Collection $g): int => $g->count())->map(fn (Collection $g): Collection => $g->values());
        $taken = [];

        // one of each product type (a bundle, a subscription, a variable product)
        foreach ($products->groupBy('type') as $ofType) {
            $taken[$ofType->first()->id] = $ofType->first();
        }

        // a round takes one of each group, spread through it: its first, then its middle, then a quarter
        // in…; round after round while there is room, a small group done once it is all taken
        for ($round = 0; count($taken) < $max && $round < $products->count(); $round++) {
            $before = count($taken);
            foreach ($groups as $group) {
                $pick = $group->get((int) floor(self::spread($round) * $group->count()));
                $pick = $pick !== null && ! isset($taken[$pick->id]) ? $pick : $group->first(fn (CatalogProduct $p): bool => ! isset($taken[$p->id]));
                if ($pick !== null) {
                    $taken[$pick->id] = $pick;
                }
                if (count($taken) >= $max) {
                    break 2;
                }
            }
            if (count($taken) === $before && $round >= $perGroup) {
                break;
            }
        }

        return collect(array_values($taken));
    }

    /** Where in a group the nth round looks: 0, ½, ¼, ¾, ⅛, ⅜… so a group is read through, not from its start. */
    private static function spread(int $round): float
    {
        if ($round === 0) {
            return 0.0;
        }
        $level = (int) floor(log($round, 2));
        $denominator = 2 ** ($level + 1);

        return (2 * ($round - 2 ** $level) + 1) / $denominator;
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
