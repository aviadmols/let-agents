<?php

namespace App\Modules\Search\Actions;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;

/**
 * The whole store for a shopper's own AI (the connector and the store's page for an AI): the
 * store's own search by spelling, no vector and nothing counted, narrowed by the filters the words
 * name (a colour, a size) when products found have them, as the browser does; with no words, every
 * product, in stock first. A page at a time, each product as an AI reads it, with its buy link.
 * Inside the shop's tenant. No model is asked.
 */
final class SearchForAgent
{
    public const MAX = 50;

    public function __construct(private readonly SearchCatalog $search) {}

    /**
     * @param  array<string, mixed>|null  $store  AgentCatalog::store, for the buy links
     * @return array{query: string, read: array<string, mixed>, total: int, page: int, pages: int, products: list<array<string, mixed>>, as_of: string}
     */
    public function handle(string $shopId, string $query, int $limit = 10, int $page = 1, ?array $store = null): array
    {
        $query = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, 200);
        $limit = max(1, min(self::MAX, $limit));
        $page = max(1, $page);
        $ids = $query === '' ? $this->everything() : $this->found($shopId, $query);
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
        ];
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

    /** @return list<string> every product the store sells now, in stock first, newest first */
    private function everything(): array
    {
        return CatalogProduct::query()->whereNull('removed_at')->orderByDesc('in_stock')->orderByDesc('source_updated_at')->orderBy('id')
            ->pluck('external_id')->map(fn ($id): string => (string) $id)->all();
    }
}
