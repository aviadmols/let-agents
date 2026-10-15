<?php

namespace App\Modules\Search\Actions;

use App\Modules\Search\Support\AgentCatalog;
use App\Modules\Search\Support\HebrewSearch;
use App\Modules\Search\Support\LoadedIndex;

/**
 * A search asked by a shopper's own AI (the connector): the store's own search by spelling, no
 * vector and nothing counted, narrowed by the filters the words name (a colour, a size) when
 * products found have them, as the browser does; the products as an AI reads them. Inside the
 * shop's tenant. No model is asked.
 */
final class SearchForAgent
{
    public const MAX = 20;

    public function __construct(private readonly SearchCatalog $search) {}

    /** @return array{query: string, read: array<string, mixed>, total: int, products: list<array<string, mixed>>, as_of: string} */
    public function handle(string $shopId, string $query, int $limit = 8): array
    {
        $query = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, 200);
        $limit = max(1, min(self::MAX, $limit));
        $ids = $query === '' ? [] : (array) ($this->search->handle($shopId, $query, ['product'], false, 1, false)['ids'] ?? []);

        // "שטיח כחול 80x150": only products with those values, when any of those found has them.
        $index = $ids === [] ? null : LoadedIndex::for($shopId);
        $codes = $index === null ? [] : array_column(HebrewSearch::facets($query, (array) ($index['facets'] ?? [])), 'code');
        if ($codes !== []) {
            $held = array_values(array_filter($ids, fn (string $id): bool => array_diff($codes, (array) ($index['records'][$id]['f'] ?? [])) === []));
            $ids = $held !== [] ? $held : $ids;
        }

        $external = array_map(fn (string $id): string => substr($id, 2), array_slice($ids, 0, $limit));

        return [
            'query' => $query,
            'read' => AgentCatalog::read($shopId, $query),
            'total' => count($ids),
            'products' => AgentCatalog::products($external),
            'as_of' => AgentCatalog::asOf($external),
        ];
    }
}
