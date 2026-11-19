<?php

namespace App\Modules\Search\Contracts;

/**
 * How a store's filter screen is arranged, as the search index applies it (ArrangeFilters wrote it, FilterLayoutGate
 * applies it to the facets): the filters' order, the order of a filter's values, the filters hidden and the values
 * moved out of a filter. Null when the store has none, or its flag (search.filter_layout) is off: the screen is then
 * the one code built, exactly as before.
 */
interface FilterLayouts
{
    /** @return array{order: list<string>, values: array<string, list<array{0: string, 1: string}>>, hide: array<string, string>, moves: array<string, list<array{0: string, 1: string, 2: string, 3: string}>>}|null */
    public function applied(string $shopId): ?array;
}
