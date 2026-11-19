<?php

namespace App\Modules\Search\Support;

use App\Modules\Assistant\Contracts\AnswerSearches;
use App\Modules\Catalog\Contracts\ProductKinds;

/**
 * The store's searches an answer about products leads into (AnswerSearches), read off the search index the drawer holds:
 * the filters the question names, read as the drawer reads a search's words (HebrewSearch::readFacets, its sure readings
 * only), the kind of the products the answer chose, and the price the question asked. A product to go with another is the
 * kind of the picks, by the values the picks share with that product. Code only, no model, any store.
 *
 * A search is offered only when it shows enough products (assistant.answer_searches_min, in stock where the store keeps
 * stock), shows at least one the answer's cards did not (a whole set shown as a grid without filters may be the same products,
 * filterable), and is not the same products as one offered before it. A product holds a
 * value when the index files it so and no check refused it, as the drawer opens it (answerSearchTurn): the count is what
 * the button opens.
 */
final class SearchesBesideAnswer implements AnswerSearches
{
    /** The readings of the question's words a search is built from: never a guess from a sound or a slip. */
    private const SURE = ['exact', 'form', 'family'];

    public function __construct(private readonly ProductKinds $kinds) {}

    public function searches(string $shopId, string $question, array $picks, ?array $goesWith, int $max, int $min, bool $beyond = true): array
    {
        $index = LoadedIndex::for($shopId);
        if ($index === null || $max < 1 || $picks === []) {
            return [];
        }
        $facets = array_values((array) ($index['facets'] ?? []));
        // a gift card is no product a search for one shows (the drawer leaves it out of any search that asks for no gift)
        $products = array_filter($index['records'], fn (array $r): bool => ($r['t'] ?? null) === 'product' && empty($r['gc']));
        $picked = array_values(array_unique(array_filter(array_map(fn ($id): string => 'p:'.$id, $picks), fn (string $id): bool => isset($products[$id]))));
        if ($picked === [] || $facets === []) {
            return [];
        }

        $typeAt = self::facetAt($facets, 'type');
        [$rest, $low, $high] = HebrewSearch::priceRange($question);
        $price = $low === null && $high === null ? null : ['min' => $low, 'max' => $high];
        $read = array_values(array_filter(HebrewSearch::readFacets($rest, $facets, HebrewSearch::readContext($index)),
            fn (array $r): bool => in_array($r['how'], self::SURE, true) && ! $r['part']));
        $askedKinds = array_values(array_map(fn (array $r): int => (int) $r['code'], array_filter($read, fn (array $r): bool => $typeAt !== null && intdiv((int) $r['code'], 1000) === $typeAt)));
        $asked = array_values(array_map(fn (array $r): int => (int) $r['code'], array_filter($read, fn (array $r): bool => $typeAt === null || intdiv((int) $r['code'], 1000) !== $typeAt)));

        // the kinds of the answer's products, the kind most of them are first; of the kinds the question names, when it names any
        $kinds = self::kindsOf($products, $picked, $typeAt);
        if ($askedKinds !== []) {
            $kinds = array_values(array_intersect($kinds, $askedKinds)) ?: $askedKinds;
        }

        $options = $goesWith !== null
            ? $this->matching($products, $facets, $picked, $kinds, (string) ($goesWith['anchor'] ?? ''), $price, $min)
            : self::asked($kinds, $askedKinds, $asked, $price);

        // where the store keeps stock, a search shows what is in stock, as the drawer's does by default
        $stocked = array_any($products, fn (array $r): bool => (int) ($r['s'] ?? 0) === 1);
        $out = [];
        $seen = [];
        foreach ($options as $chips) {
            if (count($out) >= $max) {
                break;
            }
            if ($chips === []) {
                continue;
            }
            $shown = array_keys(array_filter(self::holding($products, $chips, $price), fn (array $r): bool => ! $stocked || (int) ($r['s'] ?? 0) === 1));
            sort($shown);
            $key = implode(',', $shown);
            // enough to be a search, more than the answer already showed, and not the same products as a search before it
            if (count($shown) < $min || ($beyond && array_diff($shown, $picked) === []) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = self::described($facets, $chips, $price, count($shown));
        }

        return $out;
    }

    /**
     * A question's searches: for each kind (the one it names, else those of the answer's products), that kind with every
     * other value the question names. A question that names nothing the store files by, nor a price, has none: "מה מומלץ?"
     * is no search.
     *
     * @param  list<int>  $kinds
     * @param  list<int>  $askedKinds
     * @param  list<int>  $asked
     * @param  array{min: float|null, max: float|null}|null  $price
     * @return list<list<int>>
     */
    private static function asked(array $kinds, array $askedKinds, array $asked, ?array $price): array
    {
        if ($askedKinds === [] && $asked === [] && $price === null) {
            return [];
        }
        if ($kinds === []) {
            return [$asked];
        }

        return array_map(fn (int $kind): array => [$kind, ...$asked], $kinds);
    }

    /**
     * A product to go with another: of the picks' kind, the values of the other product the picks share, those most of them
     * share first. Every one of them that still leaves enough products, together; then each value left out on its own
     * ("טבעת · וינטג'" beside "טבעת · זהב דו-גוני · 14K"). Never the kind, a sale, a size or whom it is for (GoingWith::shareable).
     *
     * @param  array<string, array<string, mixed>>  $products
     * @param  list<array<string, mixed>>  $facets
     * @param  list<string>  $picked
     * @param  list<int>  $kinds
     * @param  array{min: float|null, max: float|null}|null  $price
     * @return list<list<int>>
     */
    private function matching(array $products, array $facets, array $picked, array $kinds, string $anchor, ?array $price, int $min): array
    {
        $own = $products['p:'.$anchor] ?? null;
        if ($own === null) {
            return [];
        }
        $shareable = GoingWith::shareable($facets);
        $values = array_values(array_filter(self::codes($own), fn (int $code): bool => isset($shareable[intdiv($code, 1000)])
            && self::shareableValue($this->kinds, $facets[intdiv($code, 1000)], $code % 1000)));
        $stocked = array_any($products, fn (array $r): bool => (int) ($r['s'] ?? 0) === 1);
        $count = fn (array $chips): int => count(array_filter(self::holding($products, $chips, $price), fn (array $r): bool => ! $stocked || (int) ($r['s'] ?? 0) === 1));

        $options = [];
        foreach ($kinds === [] ? [null] : $kinds as $kind) {
            $base = $kind === null ? [] : [$kind];
            $pool = array_values(array_filter($picked, fn (string $id): bool => $kind === null || in_array($kind, self::codes($products[$id]), true)));
            $shares = [];
            foreach ($values as $code) {
                $n = count(array_filter($pool, fn (string $id): bool => in_array($code, self::codes($products[$id]), true)));
                if ($n > 0) {
                    $shares[$code] = $n;
                }
            }
            uksort($shares, fn (int $a, int $b): int => $shares[$b] <=> $shares[$a] ?: $a <=> $b);
            $together = [];
            foreach (array_keys($shares) as $code) {
                if ($count([...$base, ...$together, $code]) >= $min) {
                    $together[] = $code;
                }
            }
            if ($together !== []) {
                sort($together);
                $options[] = [...$base, ...$together];
            }
            foreach (array_keys($shares) as $code) {
                if (! in_array($code, $together, true)) {
                    $options[] = [...$base, $code];
                }
            }
        }

        return $options;
    }

    /**
     * The products holding every value (any of a filter's values, one value of it in each chip), inside the price.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @param  list<int>  $chips
     * @param  array{min: float|null, max: float|null}|null  $price
     * @return array<string, array<string, mixed>>
     */
    private static function holding(array $products, array $chips, ?array $price): array
    {
        return array_filter($products, fn (array $r): bool => array_diff($chips, self::codes($r)) === []
            && ($price === null || HebrewSearch::inRange($r['pr'] ?? null, $price['min'], $price['max'])));
    }

    /**
     * @param  list<array<string, mixed>>  $facets
     * @param  list<int>  $chips
     * @param  array{min: float|null, max: float|null}|null  $price
     * @return array{label: string, query: string, chips: list<array{k: string, v: string, l: string}>, price: array{min: float|null, max: float|null}|null, count: int}
     */
    private static function described(array $facets, array $chips, ?array $price, int $count): array
    {
        $values = array_map(fn (int $code): array => [
            'k' => (string) ($facets[intdiv($code, 1000)]['k'] ?? ''),
            'v' => (string) ($facets[intdiv($code, 1000)]['v'][$code % 1000]['k'] ?? ''),
            'l' => (string) ($facets[intdiv($code, 1000)]['v'][$code % 1000]['l'] ?? ''),
        ], $chips);
        $labels = array_column($values, 'l');

        return ['label' => implode(' · ', $labels), 'query' => implode(' ', $labels), 'chips' => $values, 'price' => $price, 'count' => $count];
    }

    /**
     * The type values the answer's products carry, the one most of them carry first.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @param  list<string>  $picked
     * @return list<int>
     */
    private static function kindsOf(array $products, array $picked, ?int $typeAt): array
    {
        if ($typeAt === null) {
            return [];
        }
        $counts = [];
        foreach ($picked as $id) {
            foreach (self::codes($products[$id]) as $code) {
                if (intdiv($code, 1000) === $typeAt) {
                    $counts[$code] = ($counts[$code] ?? 0) + 1;
                }
            }
        }
        uksort($counts, fn (int $a, int $b): int => $counts[$b] <=> $counts[$a] ?: $a <=> $b);

        return array_keys($counts);
    }

    /** @param  list<array<string, mixed>>  $facets */
    private static function facetAt(array $facets, string $key): ?int
    {
        foreach ($facets as $at => $facet) {
            if ((string) ($facet['k'] ?? '') === $key && ($facet['v'] ?? []) !== []) {
                return (int) $at;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $facet */
    private static function shareableValue(ProductKinds $kinds, array $facet, int $value): bool
    {
        return GoingWith::shareableValue($kinds, $facet, $value);
    }

    /** @return list<int> the filter values a record carries, less those a check refused for it */
    private static function codes(array $record): array
    {
        return array_values(array_diff(array_map('intval', (array) ($record['f'] ?? [])), array_map('intval', (array) ($record['no'] ?? []))));
    }
}
