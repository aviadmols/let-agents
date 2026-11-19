<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * An expert bank measured on the store's own products, in code: for every type and value how many products its words
 * read, word by word, as whole words (PatternMatch); which products two values of a one-value attribute both read;
 * how many products have a type; how many have enough values to filter by; and the catalogue's frequent words that no
 * type and no value took. What is measured here is the only evidence an item has: nothing a model says counts as it.
 *
 * A type is read from what says what a product is (title, the store's type, its categories). A value is read from
 * everything code reads of a product, and counted only on the products of the types its attribute belongs to.
 */
final class BankEvidence
{
    private const EXAMPLES = 3;

    private const PAIRS = 12;

    private const UNTAKEN = 40;

    private const UNTYPED = 15;

    /** @var array<string, array<int, true>> the products a word reads, by where it was read and the word */
    private array $memo = [];

    /** @param  list<array{id: string, title: string, head: string, full: string}>  $texts  each product's text, as CatalogueFacts keeps it */
    public function __construct(private readonly array $texts) {}

    public function products(): int
    {
        return count($this->texts);
    }

    /**
     * @param  array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}  $bank
     * @param  array<string, int>  $frequent  the catalogue's words and phrases, with how many products say each
     * @param  int  $enough  the attributes a product must have a value of to count as one a shopper can filter
     * @return array<string, mixed>
     */
    public function measure(array $bank, array $frequent, int $enough): array
    {
        $n = count($this->texts);
        $all = $n === 0 ? [] : array_fill_keys(range(0, $n - 1), true);

        $types = [];
        $typeSets = [];
        $typed = [];
        foreach ($bank['types'] as $type) {
            [$set, $words] = $this->read($type['words'], 'head', $all);
            $typeSets[$type['key']] = $set;
            $typed += $set;
            $types[$type['key']] = ['id' => $type['id'], 'products' => count($set), 'words' => $words];
        }

        $attributes = [];
        $perProduct = [];
        foreach ($bank['attributes'] as $attribute) {
            $scope = $all;
            if ($attribute['types'] !== []) {
                $scope = [];
                foreach ($attribute['types'] as $type) {
                    $scope += $typeSets[$type] ?? [];
                }
            }
            $values = [];
            $sets = [];
            $tagged = [];
            // within one attribute the longest phrase wins: a span a longer phrase of one value took is no other value's
            $read = $this->readAttribute($attribute['values'], $scope);
            foreach ($attribute['values'] as $value) {
                [$set, $words, $elsewhere] = $read[$value['key']];
                $sets[$value['key']] = $set;
                $tagged += $set;
                $values[$value['key']] = ['id' => $value['id'], 'products' => count($set), 'words' => $words] + ($elsewhere === [] ? [] : ['elsewhere' => $elsewhere]);
            }
            foreach (array_keys($tagged) as $index) {
                $perProduct[$index] = ($perProduct[$index] ?? 0) + 1;
            }
            [$conflicted, $pairs] = $this->overlaps($sets);
            $attributes[$attribute['key']] = [
                'id' => $attribute['id'],
                'of' => count($scope),
                'products' => count($tagged),
                'share' => $scope === [] ? 0.0 : round(count($tagged) / count($scope), 3),
                'multiple' => $attribute['multiple'],
                // Products that two of its values read: for an attribute a product has one value of, each is a mistake.
                'overlapping' => $conflicted,
                'overlaps' => $pairs,
                'values' => $values,
            ];
        }

        [, $typePairs] = $this->overlaps($typeSets);
        $filterable = count(array_filter($perProduct, fn (int $count): bool => $count >= $enough));

        return [
            'products' => $n,
            'typed' => count($typed),
            'type_share' => $n === 0 ? 0.0 : round(count($typed) / $n, 3),
            'filterable' => $filterable,
            'filterable_share' => $n === 0 ? 0.0 : round($filterable / $n, 3),
            'enough' => $enough,
            'types' => $types,
            'type_overlaps' => $typePairs,
            'attributes' => $attributes,
            'untyped' => $this->titles(array_slice(array_keys(array_diff_key($all, $typed)), 0, self::UNTYPED)),
            'untaken' => self::untaken($bank, $frequent),
        ];
    }

    /**
     * The products a list of words reads within a scope, and each word's own count there. For a scope that is not the
     * whole catalogue, also the words that read more products outside it than inside: such a word means something else
     * too.
     *
     * @param  list<string>  $words
     * @param  array<int, true>  $scope
     * @return array{0: array<int, true>, 1: array<string, int>, 2: array<string, int>}
     */
    private function read(array $words, string $where, array $scope): array
    {
        $set = [];
        $counts = [];
        $elsewhere = [];
        $whole = count($scope) === count($this->texts);

        foreach ($words as $word) {
            $found = $this->found($word, $where);
            $inside = $whole ? $found : array_intersect_key($found, $scope);
            $counts[$word] = count($inside);
            $set += $inside;
            if (! $whole && count($found) - count($inside) > count($inside)) {
                $elsewhere[$word] = count($found) - count($inside);
            }
        }

        ksort($set);

        return [$set, $counts, $elsewhere];
    }

    /**
     * The values of one attribute read on the products of its scope, the longest phrase first (LongestPhrase): a span a
     * longer phrase of one value took is not read again by a shorter word of another. Each value's products and each
     * word's count are of what stands; a word's reading outside the scope is counted as it is found.
     *
     * @param  list<array<string, mixed>>  $values
     * @param  array<int, true>  $scope
     * @return array<string, array{0: array<int, true>, 1: array<string, int>, 2: array<string, int>}> by value key
     */
    private function readAttribute(array $values, array $scope): array
    {
        $whole = count($scope) === count($this->texts);
        $order = array_map(fn (array $value): string => (string) $value['key'], $values);
        $spans = [];
        $out = [];

        foreach ($values as $value) {
            $key = (string) $value['key'];
            $out[$key] = [[], array_fill_keys($value['words'], 0), []];
            foreach ($value['words'] as $word) {
                $found = $this->spans($word, 'full');
                $inside = $whole ? $found : array_intersect_key($found, $scope);
                if (! $whole && count($found) - count($inside) > count($inside)) {
                    $out[$key][2][$word] = count($found) - count($inside);
                }
                foreach ($inside as $index => $list) {
                    foreach ($list as [$offset, $length]) {
                        $spans[$index][] = ['value' => $key, 'word' => $word, 'offset' => $offset, 'length' => $length];
                    }
                }
            }
        }

        ksort($spans);
        foreach ($spans as $index => $list) {
            $counted = [];
            foreach (LongestPhrase::resolve($list, $order) as $span) {
                $out[$span['value']][0][$index] = true;
                if (! isset($counted[$span['value'].'|'.$span['word']])) {
                    $counted[$span['value'].'|'.$span['word']] = true;
                    $out[$span['value']][1][$span['word']]++;
                }
            }
        }
        foreach ($out as &$read) {
            ksort($read[0]);
        }
        unset($read);

        return $out;
    }

    /** @return array<int, true> the products whose text holds the word as a whole word */
    private function found(string $word, string $where): array
    {
        return array_map(fn (): bool => true, $this->spans($word, $where));
    }

    /** @return array<int, list<array{0: int, 1: int}>> every whole-word match of the word, by product */
    private function spans(string $word, string $where): array
    {
        return $this->memo[$where.'|'.$word] ??= (function () use ($word, $where): array {
            $pattern = ExpertBankShape::pattern($word);
            // The word's first letters, to pass quickly over the products that cannot hold it.
            $start = mb_substr((string) (preg_split('/[\s\-]+/u', $word, -1, PREG_SPLIT_NO_EMPTY)[0] ?? ''), 0, -1);
            $found = [];
            foreach ($this->texts as $index => $text) {
                if (($start === '' || str_contains($text[$where], $start)) && ($all = PatternMatch::all($text[$where], $pattern)) !== []) {
                    $found[$index] = $all;
                }
            }

            return $found;
        })();
    }

    /**
     * Products read by more than one of the sets, and the pairs that share the most, with a few titles each.
     *
     * @param  array<string, array<int, true>>  $sets  by key
     * @return array{0: int, 1: list<array{a: string, b: string, products: int, examples: list<string>}>}
     */
    private function overlaps(array $sets): array
    {
        $keys = array_map('strval', array_keys($sets));
        $pairs = [];
        $shared = [];
        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                $both = array_intersect_key($sets[$keys[$i]], $sets[$keys[$j]]);
                if ($both !== []) {
                    $shared += $both;
                    $pairs[] = ['a' => $keys[$i], 'b' => $keys[$j], 'products' => count($both), 'examples' => $this->titles(array_slice(array_keys($both), 0, self::EXAMPLES))];
                }
            }
        }
        usort($pairs, fn (array $x, array $y): int => [$y['products'], $x['a'], $x['b']] <=> [$x['products'], $y['a'], $y['b']]);

        return [count($shared), array_slice($pairs, 0, self::PAIRS)];
    }

    /**
     * The catalogue's frequent words and phrases that no type and no value took, the most common first.
     *
     * @param  array<string, int>  $frequent
     * @return array<string, int>
     */
    private static function untaken(array $bank, array $frequent): array
    {
        $taken = [];
        $take = function (array $item) use (&$taken): void {
            foreach ($item['words'] as $word) {
                $taken[$word] = true;
                foreach (CatalogueFacts::tokens($word, []) as $token) {
                    $taken[$token] = true;
                }
            }
        };
        array_map($take, $bank['types']);
        foreach ($bank['attributes'] as $attribute) {
            array_map($take, $attribute['values']);
        }

        $left = [];
        foreach ($frequent as $word => $count) {
            $word = (string) $word;
            // A phrase is taken when a word of the bank is in it; a word, when the bank has it or its plural.
            $tokens = CatalogueFacts::tokens($word, []);
            $is = isset($taken[$word]) || array_filter($tokens, fn (string $token): bool => isset($taken[$token]) || isset($taken[(string) preg_replace('/(?:s|es|ים|ות)$/u', '', $token)])) !== [];
            if (! $is) {
                $left[$word] = (int) $count;
            }
        }
        uksort($left, fn (int|string $a, int|string $b): int => [$left[$b], (string) $a] <=> [$left[$a], (string) $b]);

        return array_slice($left, 0, self::UNTAKEN, true);
    }

    /**
     * @param  list<int>  $indexes
     * @return list<string>
     */
    private function titles(array $indexes): array
    {
        return array_map(fn (int $index): string => $this->texts[$index]['title'], $indexes);
    }
}
