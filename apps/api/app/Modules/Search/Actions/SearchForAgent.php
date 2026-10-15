<?php

namespace App\Modules\Search\Actions;

use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;

/**
 * The whole store for a shopper's own AI (the connector and the store's page for an AI): the
 * store's own search by spelling, no vector and nothing counted, narrowed by the filters the words
 * name (a colour, a size) when products found have them, as the browser does; with no words, every
 * product, in stock first. Then the filters asked for, each in code (a budget holds whatever the
 * words were). A page at a time, each product as an AI reads it, with its buy link. Inside the
 * shop's tenant. No model is asked.
 */
final class SearchForAgent
{
    /** Products a page, at most, for an AI that asks (the connector). */
    public const MAX = 50;

    /** Products a page, at most, in the whole catalogue in short (StoreCatalogController). */
    public const LARGEST_PAGE = 200;

    public function __construct(private readonly SearchCatalog $search) {}

    /**
     * Filters: category (its id or its name), min_price / max_price, in_stock, attributes
     * ({"מתכת": "זהב לבן"}: the store's own filters, by their name or key), and sort ("price_asc",
     * "price_desc"; the search's own order otherwise). The answer says which were applied and which
     * the store has no such filter for, so nothing is assumed.
     *
     * @param  array<string, mixed>|null  $store  AgentCatalog::store, for the buy links
     * @param  array<string, mixed>  $filters
     * @return array{query: string, read: array<string, mixed>, total: int, page: int, pages: int, products: list<array<string, mixed>>, as_of: string, filters?: array<string, mixed>, unknown_filters?: list<string>}
     */
    public function handle(string $shopId, string $query, int $limit = 10, int $page = 1, ?array $store = null, ?string $category = null, array $filters = []): array
    {
        $query = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, 200);
        $limit = max(1, min(self::LARGEST_PAGE, $limit));
        $page = max(1, $page);
        $ids = $query === '' ? $this->everything() : $this->found($shopId, $query);
        $category = $category !== null && $category !== '' ? $category : (is_string($filters['category'] ?? null) ? trim($filters['category']) : '');
        $applied = [];
        $unknown = [];

        // One category and those under it ("גינה" holds "כלי גינה"), by its id or its name.
        if ($category !== '') {
            $inside = $this->inCategory($category);
            $ids = array_values(array_filter($ids, fn (string $id): bool => isset($inside[$id])));
            $applied['category'] = $category;
        }

        // The store's own filters, by name or key: a value the store has, or said to be unknown.
        $attributes = array_filter((array) ($filters['attributes'] ?? []), fn ($value, $key): bool => is_string($key) && is_scalar($value) && trim((string) $value) !== '', ARRAY_FILTER_USE_BOTH);
        if ($attributes !== []) {
            $index = LoadedIndex::for($shopId);
            foreach ($attributes as $name => $value) {
                $code = $index === null ? null : self::facetCode(array_values((array) ($index['facets'] ?? [])), (string) $name, (string) $value);
                if ($code === null) {
                    $unknown[] = $name.': '.$value;

                    continue;
                }
                $ids = array_values(array_filter($ids, fn (string $id): bool => in_array($code, (array) ($index['records']['p:'.$id]['f'] ?? []), true)));
                $applied['attributes'][$name] = $value;
            }
        }

        // Price, stock and order from the catalogue itself: what the store sells now.
        $min = is_numeric($filters['min_price'] ?? null) ? (float) $filters['min_price'] : null;
        $max = is_numeric($filters['max_price'] ?? null) ? (float) $filters['max_price'] : null;
        $inStock = (bool) ($filters['in_stock'] ?? false);
        $sort = in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true) ? (string) $filters['sort'] : null;
        if ($min !== null || $max !== null || $inStock || $sort !== null) {
            $known = CatalogProduct::query()->whereIn('external_id', $ids)->whereNull('removed_at')->get(['external_id', 'price', 'in_stock'])
                ->keyBy(fn (CatalogProduct $p): string => (string) $p->external_id);
            $ids = array_values(array_filter($ids, function (string $id) use ($known, $min, $max, $inStock): bool {
                $product = $known->get($id);
                $price = $product?->price === null ? null : (float) $product->price;

                return $product !== null
                    && (! $inStock || $product->in_stock)
                    && ($min === null || ($price !== null && $price >= $min))
                    && ($max === null || ($price !== null && $price <= $max));
            }));
            if ($sort !== null) {
                $price = fn (string $id): float => $known->get($id)?->price === null ? INF : (float) $known->get($id)->price;
                usort($ids, fn (string $a, string $b): int => $sort === 'price_asc' ? $price($a) <=> $price($b) : $price($b) <=> $price($a));
            }
            $applied += array_filter(['min_price' => $min, 'max_price' => $max, 'in_stock' => $inStock ?: null, 'sort' => $sort], fn ($v): bool => $v !== null);
        }

        $pages = max(1, (int) ceil(count($ids) / $limit));
        $external = array_slice($ids, ($page - 1) * $limit, $limit);

        return [
            'query' => $query,
            'read' => AgentCatalog::read($shopId, $query),
            'total' => count($ids),
            'page' => $page,
            'pages' => $pages,
            'products' => AgentCatalog::products($external, $store),
            'as_of' => AgentCatalog::asOf($external),
        ] + array_filter(['filters' => $applied ?: null, 'unknown_filters' => $unknown ?: null], fn ($v): bool => $v !== null);
    }

    /** @return list<string> external ids, best first */
    private function found(string $shopId, string $query): array
    {
        $ids = (array) ($this->search->handle($shopId, $query, ['product'], false, 1, false)['ids'] ?? []);

        // "שטיח כחול 80x150": only products with those values, when any of those found has them.
        $index = $ids === [] ? null : LoadedIndex::for($shopId);
        $codes = $index === null ? [] : array_column(HebrewSearch::facets($query, (array) ($index['facets'] ?? [])), 'code');
        if ($codes !== []) {
            $held = array_values(array_filter($ids, fn (string $id): bool => array_diff($codes, (array) ($index['records'][$id]['f'] ?? [])) === []));
            $ids = $held !== [] ? $held : $ids;
        }

        return array_map(fn (string $id): string => substr($id, 2), $ids);
    }

    /**
     * A store filter's code (facet × 1000 + value) by the filter's name or key and the value's
     * label or key, the case and a "ה" in front not minded; null when the store has no such value.
     *
     * @param  list<array<string, mixed>>  $facets
     */
    private static function facetCode(array $facets, string $name, string $value): ?int
    {
        $same = function (string $a, string $b): bool {
            $a = mb_strtolower(trim($a));
            $b = mb_strtolower(trim($b));

            return $a !== '' && ($a === $b || 'ה'.$a === $b || $a === 'ה'.$b);
        };

        foreach ($facets as $at => $facet) {
            if (! $same((string) ($facet['k'] ?? ''), $name) && ! $same((string) ($facet['l'] ?? ''), $name)) {
                continue;
            }
            foreach (array_values((array) ($facet['v'] ?? [])) as $i => $option) {
                if ($same((string) ($option['k'] ?? ''), $value) || $same((string) ($option['l'] ?? ''), $value)) {
                    return $at * 1000 + $i;
                }
            }
        }

        return null;
    }

    /** @return array<string, true> the external ids of a category's products and of the categories under it, the category by its id or its name */
    private function inCategory(string $named): array
    {
        $categories = CatalogCategory::query()->whereNull('removed_at')->get();
        $wanted = mb_strtolower(trim($named));
        $matching = $categories->where('external_id', $named);

        if ($matching->isEmpty()) {
            // By name: the category itself, or its whole path ("גינה > כלי גינה").
            $matching = $categories->filter(fn (CatalogCategory $c): bool => mb_strtolower(trim((string) $c->name)) === $wanted
                || mb_strtolower(implode(' > ', array_map('strval', (array) $c->path))) === $wanted);
        }

        if ($matching->isEmpty()) {
            return [];
        }

        $branch = $matching->flatMap(fn (CatalogCategory $c): array => $c->branchExternalIds($categories))->unique()->values()->all();

        return array_fill_keys(CatalogProduct::query()->whereNull('removed_at')
            ->whereHas('categories', fn ($q) => $q->whereIn('external_id', $branch))
            ->pluck('external_id')->map(fn ($id): string => (string) $id)->all(), true);
    }

    /** @return list<string> every product the store sells now, in stock first, newest first */
    private function everything(): array
    {
        return CatalogProduct::query()->whereNull('removed_at')->orderByDesc('in_stock')->orderByDesc('source_updated_at')->orderBy('id')
            ->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
    }
}
