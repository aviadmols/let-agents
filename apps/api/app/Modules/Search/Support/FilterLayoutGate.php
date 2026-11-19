<?php

namespace App\Modules\Search\Support;

/**
 * What code decides of a filter layout, with no model and no leniency (ArrangeFilters):
 *
 * - proposal(): the writer's reply read strictly against the screen. Only what is on it counts: a filter or a value
 *   key that is not there is ignored (an invented name, a rename, a new filter), and so is a value put under a filter
 *   it is not of. A duplicate needs the two filters to share products and to split them the same way, as measured; a
 *   value moved out of a filter needs
 *   the value it is moved into to hold its products as measured. Each change left is an item the checker scores.
 * - verdicts(): the checker's reply read strictly. It scores the ids code gave and nothing else; an item it leaves
 *   unscored, vetoes, or scores under the threshold is not applied; of two answers about one item the harsher counts.
 * - apply(): the items the checker let through, the surest first, applied only where the screen stays whole: never
 *   fewer filters than the store's minimum, never both of two duplicates, never a move into a hidden filter or one that
 *   leaves its filter with a single value.
 * - onFacets(): the applied layout on the index's facets. Codes stay as they are (facet * 1000 + value): a hidden filter
 *   is marked (hide), a moved value is listed (vh), the order is a rank (o) and a value order a list of positions (vo).
 *   A key or a label the index no longer has is passed over.
 *
 * Nothing is renamed, added or merged: the only changes are an order, a hidden filter and a value taken out of a filter
 * that another already offers.
 */
final class FilterLayoutGate
{
    /** Why a filter may be hidden; a duplicate names the filter kept (duplicate_of:<key>). */
    public const REASONS = ['duplicate', 'mixed_heap', 'too_few_products', 'says_nothing', 'unreadable_values'];

    /** A filter keeps at least this many values after values are moved out of it. */
    private const MIN_VALUES_LEFT = 2;

    /**
     * The writer's reply as items the checker scores, and what code ignored of it.
     *
     * @param  array<string, mixed>  $reply  held to filter_layout's schema already
     * @param  array{duplicate_min_shared: float, duplicate_min_same_split: float, move_min_cover: float}  $limits
     * @return array{0: list<array<string, mixed>>, 1: list<array{what: string, filter: string, key: string, why: string}>}
     */
    public static function proposal(array $reply, FilterScreen $screen, array $limits): array
    {
        $items = [];
        $ignored = [];
        $ignore = function (string $what, string $filter, string $key, string $why) use (&$ignored): void {
            $ignored[] = ['what' => $what, 'filter' => mb_substr($filter, 0, 120), 'key' => mb_substr($key, 0, 120), 'why' => $why];
        };
        $label = fn (string $key): string => (string) ($screen->filter($key)['label'] ?? '');
        $why = fn (mixed $text): string => mb_substr(is_string($text) ? $text : '', 0, 300);

        // the order of the filters: what is on the screen, once each; what it left out keeps its place after
        $now = $screen->keys();
        $order = [];
        foreach ((array) ($reply['filter_order'] ?? []) as $key) {
            $key = (string) $key;
            if ($screen->filter($key) === null) {
                $ignore('order', '', $key, 'unknown_filter');
            } elseif (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        $order = [...$order, ...array_values(array_diff($now, $order))];
        if ($order !== $now) {
            $items[] = ['id' => 'o1', 'kind' => 'order', 'order' => $order, 'labels' => array_map($label, $order), 'why' => $why($reply['filter_order_why'] ?? '')];
        }

        // the order of a filter's values: its own values, once each, the rest after in today's order
        $seen = [];
        foreach ((array) ($reply['value_orders'] ?? []) as $entry) {
            $filter = (string) ($entry['filter'] ?? '');
            if ($screen->filter($filter) === null) {
                $ignore('values', $filter, '', 'unknown_filter');

                continue;
            }
            if (isset($seen[$filter])) {
                $ignore('values', $filter, '', 'twice');

                continue;
            }
            $seen[$filter] = true;
            $keys = [];
            foreach ((array) ($entry['values'] ?? []) as $value) {
                $value = (string) $value;
                if ($screen->value($filter, $value) === null) {
                    $ignore('values', $filter, $value, 'unknown_value');
                } elseif (! in_array($value, $keys, true)) {
                    $keys[] = $value;
                }
            }
            $current = $screen->valueKeysNow($filter);
            if (count($keys) < 2 || [...$keys, ...array_values(array_diff($current, $keys))] === $current) {
                $ignore('values', $filter, '', count($keys) < 2 ? 'too_few_values' : 'same_as_now');

                continue;
            }
            $items[] = ['id' => 'v'.(count(array_filter($items, fn (array $i): bool => $i['kind'] === 'values')) + 1), 'kind' => 'values', 'filter' => $filter, 'label' => $label($filter),
                'values' => $keys, 'labels' => array_map(fn (string $v): string => (string) $screen->value($filter, $v)['label'], $keys), 'why' => $why($entry['why'] ?? '')];
        }

        // a filter hidden, for a reason of the fixed set; a duplicate only of a filter on the screen it shares products with
        $hidden = [];
        $h = 0;
        foreach ((array) ($reply['hides'] ?? []) as $entry) {
            $filter = (string) ($entry['filter'] ?? '');
            $reason = (string) ($entry['reason'] ?? '');
            $of = (string) ($entry['duplicate_of'] ?? '');
            if ($screen->filter($filter) === null) {
                $ignore('hide', $filter, '', 'unknown_filter');

                continue;
            }
            if (isset($hidden[$filter])) {
                $ignore('hide', $filter, '', 'twice');

                continue;
            }
            if (! in_array($reason, self::REASONS, true)) {
                $ignore('hide', $filter, $reason, 'unknown_reason');

                continue;
            }
            $shared = null;
            if ($reason === 'duplicate') {
                if ($of === $filter || $screen->filter($of) === null) {
                    $ignore('hide', $filter, $of, 'unknown_duplicate');

                    continue;
                }
                // two filters of one thing hold the same products and split them alike: alike labels are not enough
                $shared = $screen->shared($filter, $of);
                if ($shared < $limits['duplicate_min_shared'] || $screen->sameSplit($filter, $of) < $limits['duplicate_min_same_split']) {
                    $ignore('hide', $filter, $of, 'no_overlap');

                    continue;
                }
            }
            $hidden[$filter] = true;
            $items[] = ['id' => 'h'.++$h, 'kind' => 'hide', 'filter' => $filter, 'label' => $label($filter), 'reason' => $reason === 'duplicate' ? 'duplicate_of:'.$of : $reason]
                + ($shared === null ? [] : ['kept' => $of, 'kept_label' => $label($of), 'shared' => round($shared, 2), 'same_split' => round($screen->sameSplit($filter, $of), 2)])
                + ['why' => $why($entry['why'] ?? '')];
        }

        // a value moved out of a filter, into a value another filter already has, holding its products
        $moved = [];
        $m = 0;
        foreach ((array) ($reply['splits'] ?? []) as $entry) {
            $filter = (string) ($entry['filter'] ?? '');
            if ($screen->filter($filter) === null) {
                $ignore('move', $filter, '', 'unknown_filter');

                continue;
            }
            foreach ((array) ($entry['moves'] ?? []) as $move) {
                $value = (string) ($move['value'] ?? '');
                $into = (string) ($move['into'] ?? '');
                $as = (string) ($move['as'] ?? '');
                $problem = match (true) {
                    $screen->value($filter, $value) === null => 'unknown_value',
                    $into === $filter || $screen->filter($into) === null => 'unknown_filter',
                    $screen->value($into, $as) === null => 'unknown_value',
                    isset($moved[$filter."\n".$value]) => 'twice',
                    default => null,
                };
                if ($problem === null && $screen->cover($filter, $value, $into, $as) < $limits['move_min_cover']) {
                    $problem = 'no_overlap';
                }
                if ($problem !== null) {
                    $ignore('move', $filter, $value.' > '.$into.'.'.$as, $problem);

                    continue;
                }
                $moved[$filter."\n".$value] = true;
                $items[] = ['id' => 'm'.++$m, 'kind' => 'move', 'filter' => $filter, 'label' => $label($filter), 'value' => $value, 'value_label' => (string) $screen->value($filter, $value)['label'],
                    'into' => $into, 'into_label' => $label($into), 'as' => $as, 'as_label' => (string) $screen->value($into, $as)['label'],
                    'cover' => round($screen->cover($filter, $value, $into, $as), 2), 'why' => $why($entry['why'] ?? '')];
            }
        }

        return [$items, $ignored];
    }

    /**
     * The checker's reply, read strictly against the ids code gave.
     *
     * @param  list<string>  $ids
     * @param  list<mixed>  $answers
     * @return array{0: array<string, array{score: ?int, verdict: ?string, reason: string, applied: bool, why: ?string}>, 1: list<string>}
     */
    public static function verdicts(array $ids, array $answers, int $threshold): array
    {
        $known = array_fill_keys($ids, null);
        $ignored = [];
        foreach ($answers as $answer) {
            $id = is_array($answer) && is_string($answer['id'] ?? null) ? $answer['id'] : null;
            if ($id === null) {
                continue;
            }
            if (! array_key_exists($id, $known)) {
                // not an item code gave: an addition, or a change of its own. None is taken.
                $ignored[] = mb_substr($id, 0, 120);

                continue;
            }
            $given = $answer['score'] ?? null;
            $score = (is_int($given) || (is_float($given) && floor($given) === $given)) && $given >= 0 && $given <= 100 ? (int) $given : null;
            $verdict = in_array($answer['verdict'] ?? null, ['agree', 'veto'], true) ? $answer['verdict'] : null;
            $read = ['score' => $score, 'verdict' => $verdict, 'reason' => mb_substr((string) ($answer['reason'] ?? ''), 0, 300)];
            if ($known[$id] === null || self::harsher($read, $known[$id])) {
                $known[$id] = $read;
            }
        }

        $out = [];
        foreach ($known as $id => $read) {
            $why = match (true) {
                $read === null, $read['score'] === null, $read['verdict'] === null => 'unscored',
                $read['verdict'] === 'veto' => 'vetoed',
                $read['score'] < $threshold => 'low_score',
                default => null,
            };
            $out[(string) $id] = ($read ?? ['score' => null, 'verdict' => null, 'reason' => '']) + ['applied' => $why === null, 'why' => $why];
        }
        sort($ignored);

        return [$out, array_values(array_unique($ignored))];
    }

    /**
     * The items the checker let through, applied where the screen stays whole. The surest first (the highest score),
     * so when two cannot both be, the one the checker was surer of stays.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, array{score: ?int, applied: bool}>  $verdicts
     * @return array{0: array{order: list<string>, values: array<string, list<array{0: string, 1: string}>>, hide: array<string, string>, moves: array<string, list<array{0: string, 1: string, 2: string, 3: string}>>}, 1: list<array{id: string, why: string}>}
     */
    public static function apply(array $items, array $verdicts, FilterScreen $screen, int $minFilters): array
    {
        $passed = array_values(array_filter($items, fn (array $item): bool => ($verdicts[$item['id']]['applied'] ?? false) === true));
        usort($passed, fn (array $a, array $b): int => [$verdicts[$b['id']]['score'], $a['id']] <=> [$verdicts[$a['id']]['score'], $b['id']]);
        $refused = [];
        $applied = ['order' => [], 'values' => [], 'hide' => [], 'moves' => []];

        $visible = count($screen->filters);
        $kept = [];
        foreach (array_filter($passed, fn (array $i): bool => $i['kind'] === 'hide') as $item) {
            $why = match (true) {
                isset($kept[$item['filter']]) => 'kept_for_duplicate',
                isset($item['kept']) && isset($applied['hide'][$item['kept']]) => 'twin_hidden',
                $visible - 1 < $minFilters => 'too_few_filters',
                default => null,
            };
            if ($why !== null) {
                $refused[] = ['id' => $item['id'], 'why' => $why];

                continue;
            }
            $applied['hide'][$item['filter']] = $item['reason'];
            $visible--;
            if (isset($item['kept'])) {
                $kept[$item['kept']] = true;
            }
        }

        $left = [];
        foreach (array_filter($passed, fn (array $i): bool => $i['kind'] === 'move') as $item) {
            $left[$item['filter']] ??= count($screen->filter($item['filter'])['values']);
            $why = match (true) {
                isset($applied['hide'][$item['filter']]) => 'filter_hidden',
                isset($applied['hide'][$item['into']]) => 'target_hidden',
                $left[$item['filter']] - 1 < self::MIN_VALUES_LEFT => 'too_few_values',
                default => null,
            };
            if ($why !== null) {
                $refused[] = ['id' => $item['id'], 'why' => $why];

                continue;
            }
            $applied['moves'][$item['filter']][] = [$item['value'], $item['value_label'], $item['into'], $item['as']];
            $left[$item['filter']]--;
        }

        foreach (array_filter($passed, fn (array $i): bool => $i['kind'] === 'values') as $item) {
            if (isset($applied['hide'][$item['filter']])) {
                $refused[] = ['id' => $item['id'], 'why' => 'filter_hidden'];

                continue;
            }
            $out = array_column($applied['moves'][$item['filter']] ?? [], 0);
            $pairs = [];
            foreach ($item['values'] as $i => $value) {
                if (! in_array($value, $out, true)) {
                    $pairs[] = [$value, $item['labels'][$i]];
                }
            }
            $applied['values'][$item['filter']] = $pairs;
        }

        foreach (array_filter($passed, fn (array $i): bool => $i['kind'] === 'order') as $item) {
            $applied['order'] = array_values(array_filter($item['order'], fn (string $key): bool => ! isset($applied['hide'][$key])));
        }

        ksort($applied['values'], SORT_STRING);
        ksort($applied['hide'], SORT_STRING);
        ksort($applied['moves'], SORT_STRING);

        return [$applied, $refused];
    }

    /** Whether a layout changes nothing. */
    public static function empty(array $applied): bool
    {
        return ($applied['order'] ?? []) === [] && ($applied['values'] ?? []) === [] && ($applied['hide'] ?? []) === [] && ($applied['moves'] ?? []) === [];
    }

    /**
     * The applied layout on the index's facets, codes unchanged: a hidden filter marked `hide`, a filter's rank `o`
     * (its place in the layout's order; a filter it does not name has none and comes after, in its own order), a value
     * order `vo` (positions in its `v`; values not in it after, in the drawer's own order) and the values moved out `vh`.
     * A value is matched by its key and its label both: a key alone may name another value in a later build (a store
     * field's values are keyed by their rank).
     *
     * The layout changes the screen only, never what a filter reaches (Aviad, 19.11), checked here on the products of
     * this build, every build:
     * - a filter hidden as a duplicate leaves (`hide: 1`) only while each of its values lies wholly inside one value of
     *   the filter that stays, so every product it reached is reached there; otherwise it is only tucked away;
     * - a filter hidden for any other reason (too few products, a heap, unreadable values) is tucked away (`hide: 2`):
     *   off the row over the results, last in the filter sheet, still there to choose;
     * - a value moved out of a filter (`vh`) leaves only while every product of it holds the value it moved into.
     *
     * @param  list<array<string, mixed>>  $facets
     * @param  array<string, mixed>  $applied
     * @param  list<array<string, mixed>>  $items  the index's records (each product's `f` codes)
     * @return list<array<string, mixed>>
     */
    public static function onFacets(array $facets, array $applied, array $items = []): array
    {
        // each product's codes, grouped by filter: [filter => [value => true]]
        $held = [];
        foreach ($items as $item) {
            $by = [];
            foreach ((array) ($item['f'] ?? []) as $code) {
                $by[intdiv((int) $code, 1000)][(int) $code % 1000] = true;
            }
            if ($by !== []) {
                $held[] = $by;
            }
        }
        // every value of filter $g lies wholly inside one value of filter $k
        $inside = function (int $g, int $k) use ($held): bool {
            $common = [];
            foreach ($held as $by) {
                foreach (array_keys($by[$g] ?? []) as $v) {
                    $common[$v] = array_key_exists($v, $common) ? array_intersect_key($common[$v], $by[$k] ?? []) : ($by[$k] ?? []);
                }
            }
            foreach ($common as $values) {
                if ($values === []) {
                    return false;
                }
            }

            return $common !== [];
        };
        // every product of value $v of filter $g holds value $w of filter $k
        $within = function (int $g, int $v, int $k, int $w) use ($held): bool {
            $any = false;
            foreach ($held as $by) {
                if (isset($by[$g][$v])) {
                    if (! isset($by[$k][$w])) {
                        return false;
                    }
                    $any = true;
                }
            }

            return $any;
        };

        $at = [];
        foreach ($facets as $g => $facet) {
            $key = (string) ($facet['k'] ?? '');
            if ($key !== '' && ! isset($at[$key]) && ($facet['v'] ?? []) !== []) {
                $at[$key] = $g;
            }
        }
        $position = function (int $g, string $key, string $label) use ($facets): ?int {
            foreach ((array) ($facets[$g]['v'] ?? []) as $i => $value) {
                if ((string) ($value['k'] ?? '') === $key && (string) ($value['l'] ?? '') === $label) {
                    return $i;
                }
            }

            return null;
        };

        foreach (array_values((array) ($applied['order'] ?? [])) as $rank => $key) {
            if (isset($at[$key])) {
                $facets[$at[$key]]['o'] = $rank;
            }
        }
        foreach ((array) ($applied['hide'] ?? []) as $key => $reason) {
            if (! isset($at[$key])) {
                continue;
            }
            $kept = str_starts_with((string) $reason, 'duplicate_of:') ? substr((string) $reason, 13) : null;
            $gone = $kept !== null && isset($at[$kept]) && ! isset($applied['hide'][$kept]) && $inside($at[$key], $at[$kept]);
            $facets[$at[$key]]['hide'] = $gone ? 1 : 2;
        }
        foreach ((array) ($applied['values'] ?? []) as $key => $pairs) {
            if (! isset($at[$key])) {
                continue;
            }
            $order = array_values(array_filter(array_map(fn (array $p): ?int => $position($at[$key], (string) $p[0], (string) $p[1]), (array) $pairs), fn (?int $i): bool => $i !== null));
            if (count($order) >= 2) {
                $facets[$at[$key]]['vo'] = $order;
            }
        }
        foreach ((array) ($applied['moves'] ?? []) as $key => $moves) {
            if (! isset($at[$key])) {
                continue;
            }
            $out = [];
            foreach ((array) $moves as $m) {
                $v = $position($at[$key], (string) $m[0], (string) $m[1]);
                $into = isset($m[2]) && isset($at[(string) $m[2]]) ? $at[(string) $m[2]] : null;
                $w = $into === null ? null : array_search((string) ($m[3] ?? ''), array_map(fn (array $x): string => (string) ($x['k'] ?? ''), (array) ($facets[$into]['v'] ?? [])), true);
                if ($v !== null && $w !== false && $w !== null && $within($at[$key], $v, $into, (int) $w)) {
                    $out[] = $v;
                }
            }
            sort($out);
            if ($out !== []) {
                $facets[$at[$key]]['vh'] = $out;
            }
        }

        return $facets;
    }

    /**
     * The screen as a shopper reads it, from the screen as the models read it (FilterScreen::forModel, kept on the
     * stored layout): the filters in order with their values in order, and what is hidden. Without a layout, the screen
     * as the drawer shows it now.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $applied
     * @return array{shown: list<array{key: string, label: string, products: int, values: list<array{label: string, products: int}>, more: int}>, hidden: list<array{key: string, label: string, reason: string}>}
     */
    public static function screen(array $input, ?array $applied = null): array
    {
        $applied ??= [];
        $filters = [];
        foreach ((array) ($input['filters'] ?? []) as $filter) {
            $filters[(string) $filter['key']] = $filter;
        }
        $order = array_values(array_intersect((array) ($applied['order'] ?? []), array_keys($filters)));
        $shown = [];
        $hidden = [];
        foreach ([...$order, ...array_values(array_diff(array_keys($filters), $order))] as $key) {
            $f = $filters[$key];
            if (isset($applied['hide'][$key])) {
                $hidden[] = ['key' => (string) $key, 'label' => (string) $f['label'], 'reason' => (string) $applied['hide'][$key]];

                continue;
            }
            // its values as the models read them: in today's order already
            $now = array_values((array) $f['values']);
            $byKey = array_column($now, null, 'key');
            $first = array_values(array_intersect(array_column((array) ($applied['values'][$key] ?? []), 0), array_keys($byKey)));
            $out = array_column((array) ($applied['moves'][$key] ?? []), 0);
            $keys = array_values(array_diff([...$first, ...array_values(array_diff(array_column($now, 'key'), $first))], $out));
            $shown[] = ['key' => (string) $key, 'label' => (string) $f['label'], 'products' => (int) $f['products'],
                'values' => array_map(fn ($v): array => ['label' => (string) $byKey[$v]['label'], 'products' => (int) $byKey[$v]['products']], $keys),
                'more' => (int) ($f['values_left_out'] ?? 0)];
        }

        return ['shown' => $shown, 'hidden' => $hidden];
    }

    /** Whether answer $a is harsher than $b: no usable score before a veto, a veto before an agreement, then the lower score. */
    private static function harsher(array $a, array $b): bool
    {
        $rank = fn (array $x): array => [$x['score'] === null || $x['verdict'] === null ? 0 : 1, $x['verdict'] === 'veto' ? 0 : 1, $x['score'] ?? 0];

        return $rank($a) < $rank($b);
    }
}
