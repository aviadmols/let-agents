<?php

namespace App\Modules\Enrichment\Scanning;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Contracts\ProductKinds;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;

/**
 * How a store's catalog is divided into branches, each of which gets a vocabulary of its own
 * (docs/strict-scan.md). Code only, same plan for the same catalog.
 *
 * A top category with enough products is a branch. A category that holds a big share of the whole
 * catalog and has at least two big subcategories is split into those subcategories, again and
 * again, so "jewelry" with 900 rings, 700 earrings and 300 other pieces becomes three branches, and
 * "diamonds" is not buried inside a general vocabulary. A category too small for a vocabulary of
 * its own is left to the rest. The store's own categories are never changed.
 *
 * A campaign ("NIGHT SALE", "חדש באתר") or whom products are for ("גברים", "ילדים") is never a branch: it says
 * nothing of what its products are. When the branches cover less than WHOLE_BELOW of the catalog (a store of many
 * small categories, one a kind each), the whole catalog is one branch instead (whole: true, no branches).
 *
 * Inside the shop's tenant.
 */
final class BranchPlan
{
    /** Branches covering less than this share of the catalog give way to one vocabulary for all of it. */
    public const WHOLE_BELOW = 0.5;

    /**
     * @return array{total: int, rest: int, whole: bool, branches: list<array{key: string, name: string, path: string, root: string, products: int, split_from: string|null}>}
     */
    public static function of(?int $minProducts = null, ?int $splitPercent = null, ?int $maxBranches = null): array
    {
        $shop = app(TenantContext::class)->id();
        $min = $minProducts ?? (int) Settings::get('enrichment.branch_min_products', $shop);
        $split = ($splitPercent ?? (int) Settings::get('enrichment.branch_split_percent', $shop)) / 100;
        $max = $maxBranches ?? (int) Settings::get('enrichment.max_branches', $shop);

        $categories = CatalogCategory::query()->active()->orderBy('external_id')->get(['id', 'external_id', 'parent_external_id', 'name', 'path'])->keyBy('external_id');
        $children = [];
        foreach ($categories as $category) {
            if ($category->parent_external_id !== null && $categories->has($category->parent_external_id)) {
                $children[$category->parent_external_id][] = $category->external_id;
            }
        }

        // Products under each category (itself), as sets of product ids.
        $own = [];
        $total = 0;
        CatalogProduct::query()->active()->with('categories:id,external_id')->orderBy('id')->chunk(500, function ($products) use (&$own, &$total): void {
            foreach ($products as $product) {
                $total++;
                foreach ($product->categories as $category) {
                    $own[$category->external_id][$product->id] = true;
                }
            }
        });

        // The products of a category and everything below it, computed once each.
        $branch = [];
        $below = function (string $externalId) use (&$below, &$branch, $own, $children): array {
            if (isset($branch[$externalId])) {
                return $branch[$externalId];
            }
            $set = $own[$externalId] ?? [];
            foreach ($children[$externalId] ?? [] as $child) {
                $set += $below($child);
            }

            return $branch[$externalId] = $set;
        };

        $chosen = [];
        $kinds = app(ProductKinds::class);
        $place = function (string $externalId, ?string $splitFrom) use (&$place, &$chosen, $below, $children, $min, $split, $total, $categories, $kinds): void {
            $size = count($below($externalId));
            $name = (string) $categories[$externalId]->name;

            if ($size < $min || $kinds->campaign($name) || $kinds->onlyWhom($name)) {
                return;
            }

            $big = array_values(array_filter($children[$externalId] ?? [], fn (string $child): bool => count($below($child)) >= $min));

            if (count($big) >= 2 && $total > 0 && $size / $total > $split) {
                foreach ($big as $child) {
                    $place($child, $externalId);
                }

                return;
            }

            $chosen[$externalId] = ['external_id' => $externalId, 'products' => $size, 'split_from' => $splitFrom];
        };

        foreach ($categories as $category) {
            if ($category->parent_external_id === null || ! $categories->has($category->parent_external_id)) {
                $place($category->external_id, null);
            }
        }

        // The biggest first; the same order for the same sizes.
        uasort($chosen, fn (array $a, array $b): int => [$b['products'], $a['external_id']] <=> [$a['products'], $b['external_id']]);
        $chosen = array_slice($chosen, 0, max(1, $max), true);

        $covered = [];
        $branches = [];
        foreach ($chosen as $entry) {
            $category = $categories[$entry['external_id']];
            $covered += $below($entry['external_id']);
            $branches[] = [
                'key' => self::key($entry['external_id']),
                'name' => (string) $category->name,
                'path' => implode(' › ', (array) $category->path),
                'root' => (string) $entry['external_id'],
                'products' => $entry['products'],
                'split_from' => $entry['split_from'],
            ];
        }

        if ($total > 0 && count($covered) < $total * self::WHOLE_BELOW) {
            return ['total' => $total, 'rest' => 0, 'whole' => true, 'branches' => []];
        }

        return ['total' => $total, 'rest' => max(0, $total - count($covered)), 'whole' => false, 'branches' => $branches];
    }

    /** A vocabulary key from a category id: snake_case, starts with a letter, unique per category. */
    public static function key(string $externalId): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($externalId)), '_');

        return 'branch_'.substr($slug === '' ? substr(md5($externalId), 0, 8) : $slug, 0, 30);
    }
}
