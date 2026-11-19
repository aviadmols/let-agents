<?php

namespace App\Modules\Enrichment\Scanning;

/**
 * What code decides of an expert bank, with no model and no leniency (BuildExpertBank):
 *
 * - unseen(): a word, a value or a type that reads no product is taken out, always.
 * - checklist(): what the checker should look at, found by counting: values that read the same products, a value far
 *   broader than its siblings, a rare value, a word used for two things or said mostly elsewhere, an attribute nearly
 *   empty or nearly all one value.
 * - verdicts(): the checker's reply read strictly. It scores the ids code gave and nothing else: an id that is not in
 *   the bank is ignored, an item it left unscored is dropped, a score under the threshold drops the item, and of two
 *   answers about one item the harsher one counts.
 * - thin(): the least evidence a filter needs: a value or a type on too few products is none, and an attribute left
 *   with fewer than two values, or reaching too small a share of the products of its types, is none.
 * - bank(): whether the bank as a whole passes, on numbers measured on this store only.
 *
 * Every removal is said: the item, who took it out, in which step, and why.
 */
final class ExpertBankGate
{
    /** An attribute a shopper can filter by offers a choice: at least this many values. */
    public const MIN_VALUES = 2;

    /** A value is far broader than its siblings when it reads this many times their median, and most of the attribute. */
    private const BROAD_TIMES = 5;

    private const BROAD_SHARE = 0.5;

    /** A value is rare when it reads less than this share of the products its attribute is about. */
    private const RARE_SHARE = 0.005;

    /** An attribute is nearly empty under this share of its products, and nearly all one value over this one. */
    private const NEARLY_EMPTY = 0.15;

    private const NEARLY_ALL = 0.9;

    /** A one-value attribute whose values read the same products in more than this share of them fails the bank. */
    public const MAX_OVERLAP = 0.2;

    /**
     * Words that say a value is not known, not given or does not apply. A store's field often holds them ("Kosher: not
     * confirmed", "Material: N/A"), so they are repeated words of the catalogue and an expert may copy them as a value;
     * but a shopper never picks "unknown": it is the absence of a choice, not a choice. Kept small and of no trade: only
     * what says uncertainty or absence, in the two languages the stores write in. Matched as whole words, like any word.
     */
    public const NOT_A_CHOICE = [
        'unknown', 'not known', 'not confirmed', 'unconfirmed', 'not specified', 'unspecified', 'not stated', 'n/a', 'tbd', 'tba',
        'לא ידוע', 'לא ידועה', 'לא מאושר', 'לא מאושרת', 'לא צוין', 'לא מוגדר', 'לא רלוונטי',
    ];

    /**
     * A value whose label or words say uncertainty or absence (NOT_A_CHOICE) is no choice, and is taken out, whatever
     * the products say.
     *
     * @return array{0: array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}, 1: list<array<string, mixed>>}
     */
    public static function notChoices(array $bank, string $step): array
    {
        $patterns = array_map(ExpertBankShape::pattern(...), self::NOT_A_CHOICE);

        return self::sift($bank, $step, function (string $kind, array $item) use ($patterns): array {
            if ($kind !== 'value') {
                return [$item['words'], null, ''];
            }
            foreach ([(string) $item['label'], ...$item['words']] as $said) {
                $match = PatternMatch::first(TextNormalizer::forMatching($said), $patterns);
                if ($match !== null) {
                    return [$item['words'], 'not_a_choice', mb_substr($said, 0, 120)];
                }
            }

            return [$item['words'], null, ''];
        });
    }

    /**
     * What reads no product, taken out: each word alone, then the value or the type left with none, then the attribute
     * left with no value or with no type of those it named.
     *
     * @param  array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}  $bank
     * @param  array<string, mixed>  $measured  BankEvidence::measure() of this same bank
     * @return array{0: array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}, 1: list<array<string, mixed>>}
     */
    public static function unseen(array $bank, array $measured, string $step): array
    {
        return self::sift($bank, $step, function (string $kind, array $item, ?array $attribute) use ($measured): array {
            $counts = $kind === 'type'
                ? ($measured['types'][$item['key']]['words'] ?? [])
                : ($measured['attributes'][$attribute['key']]['values'][$item['key']]['words'] ?? []);
            $words = array_values(array_filter($item['words'], fn (string $word): bool => ($counts[$word] ?? 0) > 0));

            return [$words, $words === [] ? 'no_evidence' : null, ''];
        });
    }

    /**
     * The least evidence a filter needs. Run again on a fresh measurement until nothing more goes: a type taken out
     * changes which products an attribute is about.
     *
     * @param  array<string, mixed>  $measured  BankEvidence::measure() of this same bank
     * @param  int  $minProducts  a value or a type is a filter when at least this many products hold it
     * @param  float  $minShare  an attribute is a filter when it reaches at least this share of the products of its types
     * @param  float  $maxValueShare  a value held by at least this share of its attribute's products separates nothing
     * @return array{0: array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}, 1: list<array<string, mixed>>}
     */
    public static function thin(array $bank, array $measured, int $minProducts, float $minShare, float $maxValueShare = 1.0, string $step = 'gate'): array
    {
        [$bank, $removed] = self::sift($bank, $step, function (string $kind, array $item, ?array $attribute) use ($measured, $minProducts, $maxValueShare): array {
            $products = (int) ($kind === 'type'
                ? ($measured['types'][$item['key']]['products'] ?? 0)
                : ($measured['attributes'][$attribute['key']]['values'][$item['key']]['products'] ?? 0));
            if ($products < $minProducts) {
                return [$item['words'], $products === 0 ? 'no_evidence' : 'too_rare', (string) $products];
            }
            // A value nearly every product of its attribute holds narrows nothing: picking it returns what was there. The
            // checker is asked about it (nearly_universal) and may keep it; code does not (a "suitable for" held by 97 of 98).
            // (an attribute of one value is no filter whatever its share: it goes below as too few values)
            $of = (int) ($kind === 'value' && count($attribute['values']) >= self::MIN_VALUES ? ($measured['attributes'][$attribute['key']]['products'] ?? 0) : 0);
            if ($of > 0 && $products / $of >= $maxValueShare) {
                return [$item['words'], 'separates_nothing', $products.' of '.$of];
            }

            return [$item['words'], null, (string) $products];
        });

        $kept = [];
        foreach ($bank['attributes'] as $attribute) {
            $share = (float) ($measured['attributes'][$attribute['key']]['share'] ?? 0.0);
            if (count($attribute['values']) < self::MIN_VALUES) {
                $removed[] = self::removed($attribute, 'attribute', 'code', $step, 'too_few_values', (string) count($attribute['values']));
            } elseif ($share < $minShare) {
                $removed[] = self::removed($attribute, 'attribute', 'code', $step, 'too_thin', (string) $share);
            } else {
                $kept[] = $attribute;
            }
        }
        $bank['attributes'] = $kept;

        return [$bank, $removed];
    }

    /**
     * What the checker is asked to look at, found by counting. It decides nothing: each line points at ids and says
     * what the numbers show.
     *
     * @param  array<string, mixed>  $measured
     * @return list<array{look: string, ids: list<string>, detail: string}>
     */
    public static function checklist(array $bank, array $measured, int $minProducts): array
    {
        $list = [];
        $uses = [];

        foreach ($bank['types'] as $type) {
            foreach ($type['words'] as $word) {
                $uses[$word][] = $type['id'];
            }
            if (($measured['types'][$type['key']]['products'] ?? 0) < max($minProducts + 1, (int) ceil(self::RARE_SHARE * $measured['products']))) {
                $list[] = ['look' => 'rare', 'ids' => [$type['id']], 'detail' => ($measured['types'][$type['key']]['products'] ?? 0).' of '.$measured['products'].' products'];
            }
        }
        foreach ((array) $measured['type_overlaps'] as $pair) {
            $list[] = ['look' => 'overlap', 'ids' => ['type:'.$pair['a'], 'type:'.$pair['b']], 'detail' => $pair['products'].' products read as both: '.implode(' | ', $pair['examples'])];
        }

        foreach ($bank['attributes'] as $attribute) {
            $of = $measured['attributes'][$attribute['key']];
            $counts = array_map(fn (array $value): int => (int) $value['products'], $of['values']);

            if ($of['share'] < self::NEARLY_EMPTY) {
                $list[] = ['look' => 'nearly_empty', 'ids' => [$attribute['id']], 'detail' => $of['products'].' of the '.$of['of'].' products it is about have a value'];
            }
            foreach ($of['overlaps'] as $pair) {
                if (! $attribute['multiple']) {
                    $list[] = ['look' => 'overlap', 'ids' => ['value:'.$attribute['key'].'.'.$pair['a'], 'value:'.$attribute['key'].'.'.$pair['b']], 'detail' => $pair['products'].' products read as both, and a product has one: '.implode(' | ', $pair['examples'])];
                }
            }

            foreach ($attribute['values'] as $value) {
                $products = $counts[$value['key']] ?? 0;
                $siblings = array_values(array_diff_key($counts, [$value['key'] => true]));
                sort($siblings);
                $median = $siblings === [] ? 0 : $siblings[intdiv(count($siblings), 2)];

                if ($of['products'] > 0 && $products / $of['products'] >= self::NEARLY_ALL && count($counts) > 1) {
                    $list[] = ['look' => 'nearly_universal', 'ids' => [$attribute['id'], $value['id']], 'detail' => $products.' of the '.$of['products'].' products with a value have this one'];
                } elseif (count($siblings) >= 2 && $median > 0 && $products >= self::BROAD_TIMES * $median && $products / max(1, $of['products']) >= self::BROAD_SHARE) {
                    $list[] = ['look' => 'broad', 'ids' => [$value['id']], 'detail' => $products.' products; its siblings read about '.$median];
                }
                if ($products < max($minProducts + 1, (int) ceil(self::RARE_SHARE * $of['of']))) {
                    $list[] = ['look' => 'rare', 'ids' => [$value['id']], 'detail' => $products.' of '.$of['of'].' products'];
                }
                foreach ($value['words'] as $word) {
                    $uses[$word][] = $value['id'];
                }
                foreach (($of['values'][$value['key']]['elsewhere'] ?? []) as $word => $outside) {
                    $list[] = ['look' => 'two_meanings', 'ids' => [$value['id']], 'detail' => '"'.$word.'" is said by '.$outside.' products of other types and by '.($of['values'][$value['key']]['words'][$word] ?? 0).' of its own'];
                }
            }
        }

        ksort($uses, SORT_STRING);
        foreach ($uses as $word => $ids) {
            if (count($ids) > 1) {
                $list[] = ['look' => 'two_meanings', 'ids' => array_values(array_unique($ids)), 'detail' => '"'.$word.'" is a word of each of these'];
            }
        }

        return $list;
    }

    /**
     * The checker's reply, read strictly against the ids code gave.
     *
     * @param  list<string>  $ids  every id of the bank
     * @param  list<mixed>  $items  the checker's items, from every call
     * @return array{0: array<string, array{score: ?int, verdict: ?string, reason: string, kept: bool, why: ?string}>, 1: list<string>} by id, and the ids it named that the bank does not have
     */
    public static function verdicts(array $ids, array $items, int $threshold): array
    {
        $known = array_fill_keys($ids, null);
        $ignored = [];

        foreach ($items as $item) {
            $id = is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : null;
            if ($id === null) {
                continue;
            }
            if (! array_key_exists($id, $known)) {
                // Not an item of the bank: an addition, a new name for one, or a mistake. None of them is taken.
                $ignored[] = mb_substr($id, 0, 120);

                continue;
            }
            $given = $item['score'] ?? null;
            // A whole number from 0 to 100, or it is no score.
            $score = (is_int($given) || (is_float($given) && floor($given) === $given)) && $given >= 0 && $given <= 100 ? (int) $given : null;
            $verdict = in_array($item['verdict'] ?? null, ['keep', 'drop'], true) ? $item['verdict'] : null;
            $answer = ['score' => $score, 'verdict' => $verdict, 'reason' => mb_substr((string) ($item['reason'] ?? ''), 0, 300)];
            // Two answers about one item: the harsher counts.
            if ($known[$id] === null || self::harsher($answer, $known[$id])) {
                $known[$id] = $answer;
            }
        }

        $out = [];
        foreach ($known as $id => $answer) {
            $why = match (true) {
                $answer === null, $answer['score'] === null, $answer['verdict'] === null => 'unscored',
                $answer['verdict'] === 'drop' => 'checker_drop',
                $answer['score'] < $threshold => 'low_score',
                default => null,
            };
            $out[(string) $id] = ($answer ?? ['score' => null, 'verdict' => null, 'reason' => '']) + ['kept' => $why === null, 'why' => $why];
        }

        sort($ignored);

        return [$out, array_values(array_unique($ignored))];
    }

    /**
     * The checker's verdicts applied: it can take out, and nothing else. An attribute goes with its values; a type
     * taken out is taken off the attributes that named it, and an attribute left naming none goes too.
     *
     * @param  array<string, array{score: ?int, verdict: ?string, reason: string, kept: bool, why: ?string}>  $verdicts
     * @return array{0: array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}, 1: list<array<string, mixed>>}
     */
    public static function checked(array $bank, array $verdicts, string $step = 'check'): array
    {
        $removed = [];
        $gone = function (array $item, string $kind) use ($verdicts, &$removed, $step): bool {
            $verdict = $verdicts[$item['id']] ?? ['kept' => false, 'why' => 'unscored', 'score' => null, 'reason' => ''];
            if ($verdict['kept']) {
                return false;
            }
            $removed[] = self::removed($item, $kind, $verdict['why'] === 'unscored' ? 'code' : 'checker', $step, (string) $verdict['why'], ($verdict['score'] === null ? '' : $verdict['score'].': ').$verdict['reason']);

            return true;
        };

        $bank['types'] = array_values(array_filter($bank['types'], fn (array $type): bool => ! $gone($type, 'type')));
        $types = array_flip(array_column($bank['types'], 'key'));
        $attributes = [];
        foreach ($bank['attributes'] as $attribute) {
            if ($gone($attribute, 'attribute')) {
                continue;
            }
            $attribute['values'] = array_values(array_filter($attribute['values'], fn (array $value): bool => ! $gone($value, 'value')));
            $on = array_values(array_filter($attribute['types'], fn (string $type): bool => isset($types[$type])));
            if ($attribute['types'] !== [] && $on === []) {
                $removed[] = self::removed($attribute, 'attribute', 'code', $step, 'no_type');

                continue;
            }
            $attribute['types'] = $on;
            $attributes[] = $attribute;
        }
        $bank['attributes'] = $attributes;

        return [$bank, $removed];
    }

    /**
     * Whether the bank as a whole passes, on what was measured on this store's products and nothing else.
     *
     * @param  array<string, mixed>  $measured  of the final bank
     * @return array{passed: bool, type_share: float, min_type_share: float, filterable_share: float, min_filterable_share: float, enough: int, hard_failures: list<string>}
     */
    public static function bank(array $bank, array $measured, float $minTypeShare, float $minFilterableShare): array
    {
        $hard = [];
        if ($bank['types'] === []) {
            $hard[] = 'no_types';
        }
        if ($bank['attributes'] === []) {
            $hard[] = 'no_attributes';
        }
        foreach ($bank['attributes'] as $attribute) {
            $of = $measured['attributes'][$attribute['key']];
            if (! $attribute['multiple'] && $of['products'] > 0 && $of['overlapping'] / $of['products'] > self::MAX_OVERLAP) {
                $hard[] = 'overlap:'.$attribute['key'];
            }
        }

        return [
            'passed' => $hard === [] && $measured['type_share'] >= $minTypeShare && $measured['filterable_share'] >= $minFilterableShare,
            'type_share' => (float) $measured['type_share'],
            'min_type_share' => $minTypeShare,
            'filterable_share' => (float) $measured['filterable_share'],
            'min_filterable_share' => $minFilterableShare,
            'enough' => (int) $measured['enough'],
            'hard_failures' => $hard,
        ];
    }

    /**
     * The filters a shopper would get: the type, then each attribute, with its values and how many products hold each,
     * the most first.
     *
     * @param  array<string, mixed>  $measured
     * @return list<array{label: string, products: int, values: list<array{label: string, products: int}>}>
     */
    public static function filters(array $bank, array $measured, string $typeLabel): array
    {
        $rows = fn (array $items, array $counts): array => self::byCount(array_map(fn (array $item): array => ['label' => (string) $item['label'], 'products' => (int) ($counts[$item['key']]['products'] ?? 0)], $items));
        $out = $bank['types'] === [] ? [] : [['label' => $typeLabel, 'products' => (int) $measured['typed'], 'values' => $rows($bank['types'], $measured['types'])]];
        foreach ($bank['attributes'] as $attribute) {
            $of = $measured['attributes'][$attribute['key']];
            $out[] = ['label' => (string) $attribute['label'], 'products' => (int) $of['products'], 'values' => $rows($attribute['values'], $of['values'])];
        }

        return $out;
    }

    /**
     * Walks every type and value: the judge says the words it keeps and, when the item goes, why.
     *
     * @param  callable(string, array<string, mixed>, ?array<string, mixed>): array{0: list<string>, 1: ?string, 2: string}  $judge
     * @return array{0: array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}, 1: list<array<string, mixed>>}
     */
    private static function sift(array $bank, string $step, callable $judge): array
    {
        $removed = [];
        $keep = function (array $items, string $kind, ?array $attribute) use ($judge, &$removed, $step): array {
            $kept = [];
            foreach ($items as $item) {
                [$words, $why, $detail] = $judge($kind, $item, $attribute);
                if ($why !== null) {
                    $removed[] = self::removed($item, $kind, 'code', $step, $why, $detail);

                    continue;
                }
                foreach (array_diff($item['words'], $words) as $word) {
                    $removed[] = ['id' => $item['id'], 'kind' => 'word', 'label' => $word, 'by' => 'code', 'step' => $step, 'why' => 'no_evidence', 'detail' => ''];
                }
                $kept[] = ['words' => array_values($words)] + $item;
            }

            return $kept;
        };

        $bank['types'] = $keep($bank['types'], 'type', null);
        $types = array_flip(array_column($bank['types'], 'key'));
        $attributes = [];
        foreach ($bank['attributes'] as $attribute) {
            $attribute['values'] = $keep($attribute['values'], 'value', $attribute);
            $on = array_values(array_filter($attribute['types'], fn (string $type): bool => isset($types[$type])));
            if ($attribute['values'] === []) {
                $removed[] = self::removed($attribute, 'attribute', 'code', $step, 'no_values');
            } elseif ($attribute['types'] !== [] && $on === []) {
                $removed[] = self::removed($attribute, 'attribute', 'code', $step, 'no_type');
            } else {
                $attributes[] = ['types' => $on] + $attribute;
            }
        }
        $bank['attributes'] = $attributes;

        return [$bank, $removed];
    }

    /** @return array{id: string, kind: string, label: string, by: string, step: string, why: string, detail: string} */
    private static function removed(array $item, string $kind, string $by, string $step, string $why, string $detail = ''): array
    {
        return ['id' => (string) $item['id'], 'kind' => $kind, 'label' => (string) $item['label'], 'by' => $by, 'step' => $step, 'why' => $why, 'detail' => mb_substr($detail, 0, 320)];
    }

    /** Whether answer $a is harsher than $b: no usable score before a drop, a drop before a keep, then the lower score. */
    private static function harsher(array $a, array $b): bool
    {
        $rank = fn (array $x): array => [$x['score'] === null || $x['verdict'] === null ? 0 : 1, $x['verdict'] === 'drop' ? 0 : 1, $x['score'] ?? 0];

        return $rank($a) < $rank($b);
    }

    /**
     * @param  list<array{label: string, products: int}>  $rows
     * @return list<array{label: string, products: int}>
     */
    private static function byCount(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => [$b['products'], $a['label']] <=> [$a['products'], $b['label']]);

        return $rows;
    }
}
