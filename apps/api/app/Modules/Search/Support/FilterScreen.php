<?php

namespace App\Modules\Search\Support;

/**
 * The filter screen a store's shoppers see, as code reads it from the store's search index, with no model: every
 * filter the drawer would offer (its key, label and source), each value's label and how many products hold it, how
 * many products each filter covers, and what the filters and values share. What ArrangeFilters hands its two models,
 * and what FilterLayoutGate checks their answers against.
 *
 * A filter is offered as the drawer offers it (facetChips): one with values some product holds, never a value named
 * "other", never a tag filter of day ranges beside the store's own length filter (twinOfLength). A layout already on
 * the index (hide, o, vo, vh) is not read: the screen is always the one code built.
 *
 * Measured on products only, in the index's own order, values the most products first: the same index gives the same
 * screen, byte for byte, so the fingerprint asks nothing twice.
 */
final class FilterScreen
{
    /** A filter pair is listed when the smaller one's products are at least this share in the other too. */
    private const PAIR_MIN_SHARED = 0.3;

    /** A value pair holds "nearly the same products" when their products in common are this share of the larger. */
    private const VALUE_MIN_SAME = 0.8;

    /** At most this many filter pairs and value pairs go to the models: the strongest first. */
    private const MAX_FILTER_PAIRS = 80;

    private const MAX_VALUE_PAIRS = 150;

    /** The drawer never offers a value with this name (facetChips). */
    private const OTHER = '/^(אחר|other)$/iu';

    /** A tag bank's filter of day ranges, as the drawer's twinOfLength reads its values. */
    private const DAY_RANGE = '/^\s*\d+\s*(?:[–-]\s*\d+|\+|to\s+\d+|עד\s+\d+)?\s*(?:days?|nights?|ימים|לילות)\s*$/iu';

    /**
     * @var list<array{key: string, label: string, source: string, at: int, products: int, numbered: bool,
     *     values: list<array{key: string, label: string, products: int, at: int}>}> in the index's order, values the most products first
     */
    public readonly array $filters;

    /** @var array<string, int> products both values hold, by "codeA|codeB" (codeA < codeB) */
    private array $both = [];

    /** @var array<int, int> products a value holds, by its code */
    private array $held = [];

    /** @var array<string, int> the index of each filter in, by its key */
    private array $byKey = [];

    /** @var list<array{a: string, b: string, both: int, shared: float, same_split: float}> the pairs the models read: enough shared, the strongest first, cut */
    public readonly array $filterOverlaps;

    /** @var array<string, array{shared: float, same_split: float}> every pair of filters with products in common, by "a|b" both ways: what the gate reads */
    private array $pairs = [];

    /** @var list<array{a: array{filter: string, value: string}, b: array{filter: string, value: string}, both: int, a_products: int, b_products: int, same_label: bool}> */
    public readonly array $valueOverlaps;

    /** How many products the index holds. */
    public readonly int $products;

    /** @param  array<string, mixed>  $index  the store's search index, as stored (SearchIndex::items, decoded) */
    public function __construct(array $index)
    {
        $facets = array_values(array_filter((array) ($index['facets'] ?? []), 'is_array'));
        $hasLength = in_array('length', array_map(fn (array $f): string => (string) ($f['k'] ?? ''), $facets), true);

        // the codes each product holds, of values the drawer would offer
        $offered = [];
        $taken = [];
        foreach ($facets as $g => $facet) {
            $key = (string) ($facet['k'] ?? '');
            $values = array_values(array_filter((array) ($facet['v'] ?? []), 'is_array'));
            if ($key === '' || isset($taken[$key]) || $values === [] || ($hasLength && self::twinOfLength($key, $values))) {
                continue;
            }
            foreach ($values as $i => $value) {
                if (preg_match(self::OTHER, trim((string) ($value['l'] ?? ''))) !== 1) {
                    $offered[$g * 1000 + $i] = true;
                }
            }
            $taken[$key] = true;
        }

        $perProduct = [];
        $products = 0;
        foreach ((array) ($index['items'] ?? []) as $item) {
            if (! is_array($item) || ($item['t'] ?? null) !== 'product') {
                continue;
            }
            $products++;
            $codes = array_values(array_unique(array_filter(array_map('intval', (array) ($item['f'] ?? [])), fn (int $code): bool => isset($offered[$code]))));
            sort($codes);
            if ($codes !== []) {
                $perProduct[] = $codes;
            }
        }
        $this->products = $products;

        $facetProducts = [];
        $facetPair = [];
        $inFacet = [];
        foreach ($perProduct as $codes) {
            $groups = [];
            foreach ($codes as $code) {
                $this->held[$code] = ($this->held[$code] ?? 0) + 1;
                $groups[intdiv($code, 1000)] = true;
            }
            $groups = array_keys($groups);
            foreach ($groups as $g) {
                $facetProducts[$g] = ($facetProducts[$g] ?? 0) + 1;
            }
            foreach ($groups as $x => $g) {
                foreach (array_slice($groups, $x + 1) as $h) {
                    $facetPair[$g.'|'.$h] = ($facetPair[$g.'|'.$h] ?? 0) + 1;
                }
            }
            foreach ($codes as $x => $a) {
                foreach ($groups as $g) {
                    if ($g !== intdiv($a, 1000)) {
                        $inFacet[$a][$g] = ($inFacet[$a][$g] ?? 0) + 1;
                    }
                }
                foreach (array_slice($codes, $x + 1) as $b) {
                    if (intdiv($a, 1000) !== intdiv($b, 1000)) {
                        $this->both[$a.'|'.$b] = ($this->both[$a.'|'.$b] ?? 0) + 1;
                    }
                }
            }
        }

        $filters = [];
        foreach ($facets as $g => $facet) {
            $key = (string) ($facet['k'] ?? '');
            if (! isset($facetProducts[$g]) || isset($this->byKey[$key])) {
                continue;
            }
            $all = array_values(array_filter((array) ($facet['v'] ?? []), 'is_array'));
            $values = [];
            foreach ($all as $i => $value) {
                $n = $this->held[$g * 1000 + $i] ?? 0;
                if ($n > 0) {
                    $values[] = ['key' => (string) ($value['k'] ?? ''), 'label' => (string) ($value['l'] ?? ''), 'products' => $n, 'at' => $i];
                }
            }
            usort($values, fn (array $a, array $b): int => [$b['products'], $a['at']] <=> [$a['products'], $b['at']]);
            $this->byKey[$key] = count($filters);
            $filters[] = [
                'key' => $key,
                'label' => (string) ($facet['l'] ?? ''),
                'source' => self::source($key),
                'at' => $g,
                'products' => $facetProducts[$g],
                'numbered' => self::numbered($key, (string) ($facet['l'] ?? ''), $all),
                'values' => $values,
            ];
        }
        $this->filters = $filters;

        $this->filterOverlaps = $this->filterPairs($facetPair, $facetProducts, $inFacet);
        $this->valueOverlaps = $this->valuePairs();
    }

    /** @return array{key: string, label: string, source: string, at: int, products: int, numbered: bool, values: list<array{key: string, label: string, products: int, at: int}>}|null */
    public function filter(string $key): ?array
    {
        return isset($this->byKey[$key]) ? $this->filters[$this->byKey[$key]] : null;
    }

    /** @return array{key: string, label: string, products: int, at: int}|null */
    public function value(string $filter, string $value): ?array
    {
        foreach ($this->filter($filter)['values'] ?? [] as $v) {
            if ($v['key'] === $value) {
                return $v;
            }
        }

        return null;
    }

    /** The keys of the filters, in the order the drawer shows them now (the index's). @return list<string> */
    public function keys(): array
    {
        return array_column($this->filters, 'key');
    }

    /**
     * A filter's value keys in the order the drawer shows them now: a numbered filter by its numbers (numberedFacet),
     * any other the most products first.
     *
     * @return list<string>
     */
    public function valueKeysNow(string $filter): array
    {
        $f = $this->filter($filter);

        return $f === null ? [] : array_column(self::inOrderNow($f['key'], $f['label'], $f['numbered'], $f['values']), 'key');
    }

    /**
     * Values, the most products first, in the order the drawer shows them now: a numbered filter's by the number each
     * opens with (a size filter's by the whole label as a number), those with none after in the store's own order (their
     * place in the index, as the drawer's bySize falls back to); any other's as they are.
     *
     * @param  list<array{key: string, label: string, at?: int}>  $values
     * @return list<array{key: string, label: string, at?: int}>
     */
    public static function inOrderNow(string $key, string $label, bool $numbered, array $values): array
    {
        if (! $numbered) {
            return array_values($values);
        }
        $pattern = self::sizeFacet($key, $label) ? '/^\s*(\d+(?:[.,]\d+)?)\s*$/u' : '/^\s*(\d+(?:[.,]\d+)?)/u';
        $number = fn (array $v): ?float => preg_match($pattern, (string) $v['label'], $m) === 1 ? (float) str_replace(',', '.', $m[1]) : null;
        $rows = array_map(fn (array $v, int $i): array => [$number($v), (int) ($v['at'] ?? $i), $v], array_values($values), array_keys(array_values($values)));
        usort($rows, function (array $a, array $b): int {
            if ($a[0] !== null && $b[0] !== null) {
                return [$a[0], $a[1]] <=> [$b[0], $b[1]];
            }

            return $a[0] !== null ? -1 : ($b[0] !== null ? 1 : $a[1] <=> $b[1]);
        });

        return array_column($rows, 2);
    }

    /**
     * The share of a value's products another filter's value holds too (0 to 1): what a move of the value out of its
     * filter rests on.
     */
    public function cover(string $filter, string $value, string $into, string $as): float
    {
        $f = $this->filter($filter);
        $t = $this->filter($into);
        $a = $this->value($filter, $value);
        $b = $this->value($into, $as);
        if ($f === null || $t === null || $a === null || $b === null || $a['products'] === 0) {
            return 0.0;
        }
        $x = $f['at'] * 1000 + $a['at'];
        $y = $t['at'] * 1000 + $b['at'];

        return ($this->both[min($x, $y).'|'.max($x, $y)] ?? 0) / $a['products'];
    }

    /** The share of the smaller filter's products the other holds too (0 to 1). */
    public function shared(string $a, string $b): float
    {
        return $this->pairs[$a.'|'.$b]['shared'] ?? 0.0;
    }

    /** How closely two filters split the products they share the same way (0 to 1; 1: each value of one inside one of the other). */
    public function sameSplit(string $a, string $b): float
    {
        return $this->pairs[$a.'|'.$b]['same_split'] ?? 0.0;
    }

    /** How many values the screen offers, every filter together. */
    public function valueCount(): int
    {
        return array_sum(array_map(fn (array $f): int => count($f['values']), $this->filters));
    }

    /**
     * The screen as the models read it: a filter's values cut at $maxValues (those with the most products), in the order
     * the drawer shows them now, with how many more there are. Pairs sorted, so the same screen is the same input.
     *
     * @param  array{products: int, top_categories: list<array{name: string, products: int}>}  $catalogue
     * @return array<string, mixed>
     */
    public function forModel(string $language, array $catalogue, int $maxValues, int $minFilters): array
    {
        return [
            'language' => $language,
            'catalogue' => $catalogue,
            'filters' => array_map(fn (array $f): array => [
                'key' => $f['key'],
                'label' => $f['label'],
                'source' => $f['source'],
                'products' => $f['products'],
                'order_now' => $f['numbered'] ? 'number' : 'count',
                'values' => array_map(fn (array $v): array => ['key' => $v['key'], 'label' => $v['label'], 'products' => $v['products']],
                    self::inOrderNow($f['key'], $f['label'], $f['numbered'], array_slice($f['values'], 0, $maxValues))),
                'values_left_out' => max(0, count($f['values']) - $maxValues),
            ], $this->filters),
            'filter_overlaps' => $this->filterOverlaps,
            'value_overlaps' => $this->valueOverlaps,
            'min_filters' => $minFilters,
        ];
    }

    /**
     * Where code took a filter from, by its key (BuildSearchIndex): what the writer weighs when two say one thing.
     */
    public static function source(string $key): string
    {
        return match (true) {
            $key === 'type' => 'type',
            $key === 'category' => 'category',
            str_starts_with($key, 'tag:') => 'tag_bank',
            $key === 'store_tags' => 'store_tags',
            str_starts_with($key, 'field:') => 'store_field',
            $key === 'length' => 'length',
            $key === 'variant_color', $key === 'variant_size' => 'variant',
            $key === 'brand' => 'brand',
            $key === 'deal' => 'deal',
            $key === 'audience' => 'audience',
            default => 'vocabulary',
        };
    }

    /** A size filter, as the drawer reads one (sizeFacet). */
    private static function sizeFacet(string $key, string $label): bool
    {
        return preg_match('/size|מידה|מידות/iu', $key.' '.$label) === 1;
    }

    /** A filter whose values each open with a number, or a size filter: in number order in the drawer (numberedFacet). */
    private static function numbered(string $key, string $label, array $values): bool
    {
        return self::sizeFacet($key, $label) || (count($values) > 1 && array_all($values, fn (array $v): bool => preg_match('/^\s*\d/u', (string) ($v['l'] ?? '')) === 1));
    }

    /** @param  list<array<string, mixed>>  $values */
    private static function twinOfLength(string $key, array $values): bool
    {
        return str_starts_with($key, 'tag:') && array_all($values, fn (array $v): bool => preg_match(self::DAY_RANGE, (string) ($v['l'] ?? '')) === 1);
    }

    /**
     * @param  array<string, int>  $facetPair
     * @param  array<int, int>  $facetProducts
     * @param  array<int, array<int, int>>  $inFacet
     * @return list<array{a: string, b: string, both: int, shared: float, same_split: float}>
     */
    private function filterPairs(array $facetPair, array $facetProducts, array $inFacet): array
    {
        // the most products one value of each filter shares with a single value of another: how alike they split
        $best = [];
        foreach ($this->both as $pair => $n) {
            [$a, $b] = array_map('intval', explode('|', $pair));
            $best[$a][intdiv($b, 1000)] = max($best[$a][intdiv($b, 1000)] ?? 0, $n);
            $best[$b][intdiv($a, 1000)] = max($best[$b][intdiv($a, 1000)] ?? 0, $n);
        }
        $purity = function (int $g, int $h) use ($best, $inFacet): float {
            $top = 0;
            $all = 0;
            foreach ($inFacet as $code => $by) {
                if (intdiv($code, 1000) === $g && isset($by[$h])) {
                    $all += $by[$h];
                    $top += $best[$code][$h] ?? 0;
                }
            }

            return $all === 0 ? 0.0 : $top / $all;
        };

        $keyOf = array_column($this->filters, 'key', 'at');
        $out = [];
        foreach ($facetPair as $pair => $n) {
            [$g, $h] = array_map('intval', explode('|', $pair));
            if (! isset($keyOf[$g], $keyOf[$h]) || $n < 2) {
                continue;
            }
            $shared = $n / max(1, min($facetProducts[$g], $facetProducts[$h]));
            $pair = ['a' => $keyOf[$g], 'b' => $keyOf[$h], 'both' => $n, 'shared' => round($shared, 2), 'same_split' => round(max($purity($g, $h), $purity($h, $g)), 2)];
            $this->pairs[$keyOf[$g].'|'.$keyOf[$h]] = $this->pairs[$keyOf[$h].'|'.$keyOf[$g]] = ['shared' => $pair['shared'], 'same_split' => $pair['same_split']];
            if ($shared >= self::PAIR_MIN_SHARED) {
                $out[] = $pair;
            }
        }
        usort($out, fn (array $x, array $y): int => [$y['shared'], $y['same_split'], $y['both'], $x['a'], $x['b']] <=> [$x['shared'], $x['same_split'], $x['both'], $y['a'], $y['b']]);

        return array_slice($out, 0, self::MAX_FILTER_PAIRS);
    }

    /** @return list<array{a: array{filter: string, value: string}, b: array{filter: string, value: string}, both: int, a_products: int, b_products: int, same_label: bool}> */
    private function valuePairs(): array
    {
        $where = [];
        foreach ($this->filters as $f) {
            foreach ($f['values'] as $v) {
                $where[$f['at'] * 1000 + $v['at']] = [$f['key'], $v['key'], self::plain($v['label']), $v['products']];
            }
        }

        $out = [];
        $add = function (int $a, int $b, int $n) use (&$out, $where): void {
            [$fa, $va, $la, $pa] = $where[$a];
            [$fb, $vb, $lb, $pb] = $where[$b];
            $out[$a.'|'.$b] = ['a' => ['filter' => $fa, 'value' => $va], 'b' => ['filter' => $fb, 'value' => $vb], 'both' => $n, 'a_products' => $pa, 'b_products' => $pb, 'same_label' => $la === $lb];
        };
        foreach ($this->both as $pair => $n) {
            [$a, $b] = array_map('intval', explode('|', $pair));
            if (isset($where[$a], $where[$b]) && $n >= 2 && $n / max($where[$a][3], $where[$b][3]) >= self::VALUE_MIN_SAME) {
                $add($a, $b, $n);
            }
        }
        // the same name in two filters, whatever their products: one thing said twice, or two things one name
        $byLabel = [];
        foreach ($where as $code => [, , $label]) {
            $byLabel[$label][] = $code;
        }
        ksort($byLabel, SORT_STRING);
        foreach ($byLabel as $codes) {
            sort($codes);
            foreach ($codes as $x => $a) {
                foreach (array_slice($codes, $x + 1) as $b) {
                    if (intdiv($a, 1000) !== intdiv($b, 1000) && ! isset($out[$a.'|'.$b])) {
                        $add($a, $b, $this->both[$a.'|'.$b] ?? 0);
                    }
                }
            }
        }

        $out = array_values($out);
        usort($out, fn (array $x, array $y): int => [$y['both'], $y['same_label'], $x['a']['filter'], $x['a']['value'], $x['b']['filter'], $x['b']['value']]
            <=> [$x['both'], $x['same_label'], $y['a']['filter'], $y['a']['value'], $y['b']['filter'], $y['b']['value']]);

        return array_slice($out, 0, self::MAX_VALUE_PAIRS);
    }

    /** A label as two filters' names are compared: lower case, one space between words. */
    private static function plain(string $label): string
    {
        return (string) preg_replace('/\s+/u', ' ', preg_replace('/^\s+|\s+$/u', '', mb_strtolower($label)));
    }
}
