<?php

namespace App\Modules\Search\Support;

use App\Modules\Catalog\Models\CatalogProduct;

/**
 * Places what the reader saw in a photo ("ברז מטבח") among the shop's own categories and
 * products, by words, before any model is asked to choose: the category named like it, else the
 * category most of the products named like it share, else the words themselves when products
 * are named like it.
 *
 * Words are compared whole, by the forms each may stand for: a word and the singular it looks
 * like the plural of (ברזים: ברז; מברגות: מברג and מברגה), with ו and י dropped after the
 * first letter. So ברז places in ברזים and ברזי מטבח, never in ברזל. The first word of what was
 * seen is its head noun, as Hebrew puts it, and must be there; the other words count for more.
 */
final class PhotoPlacer
{
    /** Category records in the index carry this prefix before the category's external id. */
    public const CATEGORY = 'k:';

    /** Products named like the object that take part in the vote for its category. */
    private const PRODUCTS = 60;

    /** A category shared by at least this share of the voting products is nearly as shared as the top one. */
    private const NEAR_SHARE = 0.6;

    /** @var array<string, list<list<string>>> each record's words, by record id */
    private array $words = [];

    /** @var array<string, list<string>> product external id => its categories' external ids */
    private array $categories = [];

    /** @param array{engine: array<string, mixed>, records: array<string, array<string, mixed>>} $index */
    public function __construct(private readonly array $index) {}

    /**
     * The forms one normalized word may stand for: itself, and the singular it would be the
     * plural of. Final letters are already plain (ם is מ), so a plural ends in ימ or ות.
     *
     * @return list<string>
     */
    public static function forms(string $word): array
    {
        $forms = [$word];

        if (preg_match('/^[א-ת]+$/u', $word) === 1) {
            $length = mb_strlen($word);

            if ($length >= 5 && str_ends_with($word, 'יות')) {
                $forms[] = mb_substr($word, 0, -3).'ית';
            } elseif ($length >= 4 && str_ends_with($word, 'ות')) {
                $base = mb_substr($word, 0, -2);
                array_push($forms, $base, $base.'ה');
            } elseif ($length >= 4 && str_ends_with($word, 'ימ')) {
                $forms[] = mb_substr($word, 0, -2);
            }
        }

        return array_values(array_unique(array_map(
            fn (string $form): string => mb_substr($form, 0, 1).preg_replace('/[וי]/u', '', mb_substr($form, 1)),
            $forms,
        )));
    }

    /**
     * A phrase's words, the head noun first, each as its forms, without repeats.
     *
     * @return list<list<string>>
     */
    public static function words(string $text): array
    {
        $out = [];
        $seen = [];

        foreach (explode(' ', HebrewSearch::normalize($text)) as $token) {
            if ($token === '' || mb_strlen($token) < 2) {
                continue;
            }

            $forms = self::forms($token);

            if (! in_array($forms[0], $seen, true)) {
                $seen[] = $forms[0];
                $out[] = $forms;
            }
        }

        return $out;
    }

    /** Whether one phrase holds another's head noun: the shop's word for what the team said. */
    public static function agrees(string $expected, string $actual): bool
    {
        $words = self::words($expected);

        return $words !== [] && self::holds(self::words($actual), $words[0]);
    }

    /**
     * Where the object belongs, trying each of its names in turn: the object first, then its
     * other names, the first that places wins.
     *
     * @param  list<string>  $also
     * @return array{main: array<string, mixed>|null, vote: list<string>} the main tag, and the categories the
     *                                                                    object's products share, most shared first
     */
    public function place(string $object, array $also = []): array
    {
        foreach ([$object, ...$also] as $name) {
            $words = self::words($name);

            if ($words === []) {
                continue;
            }

            if (($category = $this->category($words)) !== null) {
                return ['main' => $this->categoryTag($category, true), 'vote' => [substr((string) $category['id'], 2)]];
            }

            $products = $this->products($words);

            if ($products === []) {
                continue;
            }

            $vote = $this->vote($products);
            $tag = $vote === [] ? null : $this->categoryTag($this->record(self::CATEGORY.$vote[0]), true);

            return ['main' => $tag ?? ['title' => $name, 'kind' => 'words', 'main' => true], 'vote' => $vote];
        }

        return ['main' => null, 'vote' => []];
    }

    /** A tag for something else seen in the photo: the category named like it, else its words when products are named like it. */
    public function tagFor(string $name): ?array
    {
        $words = self::words($name);

        if ($words === []) {
            return null;
        }

        if (($category = $this->category($words)) !== null) {
            return $this->categoryTag($category);
        }

        return $this->products($words) === [] ? null : ['title' => $name, 'kind' => 'words'];
    }

    /**
     * A short list of categories for a second look: where the object was placed, what its products
     * share, what the nearest pictures belong to, and what its other names are called. In name
     * order, so the same photo asks the same way.
     *
     * @param  array{main: array<string, mixed>|null, vote: list<string>}  $placed
     * @param  list<array{external_id: string, similarity: float}>  $hits  nearest pictures first
     * @param  list<string>  $also
     * @return list<array{id: string, title: string, n: int}>
     */
    public function candidates(array $placed, array $hits, array $also, int $limit): array
    {
        $ids = [];

        if (isset($placed['main']['id'])) {
            $ids[] = substr((string) $placed['main']['id'], 2);
        }

        array_push($ids, ...array_slice($placed['vote'], 0, 5));

        foreach ($also as $name) {
            $words = self::words($name);

            if ($words !== [] && ($category = $this->category($words)) !== null) {
                $ids[] = substr((string) $category['id'], 2);
            }
        }

        $counts = [];

        foreach ($this->categoriesOf(array_map('strval', array_slice(array_column($hits, 'external_id'), 0, 20))) as $categories) {
            foreach ($categories as $category) {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
        }

        arsort($counts);
        array_push($ids, ...array_map('strval', array_slice(array_keys($counts), 0, 6)));

        $out = [];

        foreach (array_unique($ids) as $id) {
            $record = $this->record(self::CATEGORY.$id);

            if ($record !== null && count($out) < $limit) {
                $out[] = ['id' => (string) $id, 'title' => (string) $record['title'], 'n' => (int) ($record['n'] ?? 0)];
            }
        }

        usort($out, fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function categoryTag(?array $record, bool $main = false): ?array
    {
        if ($record === null) {
            return null;
        }

        return array_filter([
            'title' => (string) $record['title'],
            'kind' => 'category',
            'id' => (string) $record['id'],
            'url' => $record['url'] ?? null,
            'main' => $main ?: null,
        ], fn ($value): bool => $value !== null);
    }

    /** @return array<string, mixed>|null the index record of a category with products */
    public function record(string $id): ?array
    {
        $record = $this->index['records'][$id] ?? null;

        return is_array($record) && (int) ($record['n'] ?? 0) > 0 ? $record : null;
    }

    /**
     * Each product's categories, from the catalog, asked once.
     *
     * @param  list<string>  $externalIds
     * @return array<string, list<string>>
     */
    public function categoriesOf(array $externalIds): array
    {
        $missing = array_values(array_diff(array_unique($externalIds), array_keys($this->categories)));

        foreach (array_chunk($missing, 200) as $chunk) {
            foreach ($chunk as $id) {
                $this->categories[$id] = [];
            }

            foreach (CatalogProduct::query()->whereIn('external_id', $chunk)->with('categories')->get() as $product) {
                $this->categories[(string) $product->external_id] = $product->categories->map(fn ($c): string => (string) $c->external_id)->all();
            }
        }

        $out = [];

        foreach ($externalIds as $id) {
            $out[$id] = $this->categories[$id] ?? [];
        }

        return $out;
    }

    /**
     * The category named like the object: its name holds the head noun, and as many of the other
     * words as possible; a name made only of the object's words, or led by its head noun, before
     * one that holds more; the bigger category when still equal.
     *
     * @param  list<list<string>>  $words
     */
    private function category(array $words): ?array
    {
        $best = null;
        $bestKey = null;

        foreach ($this->index['records'] as $id => $record) {
            if (($record['t'] ?? null) !== 'category' || (int) ($record['n'] ?? 0) <= 0) {
                continue;
            }

            $own = $this->wordsOf((string) $id, (string) $record['title']);

            if ($own === [] || ! self::holds($own, $words[0])) {
                continue;
            }

            $key = [
                self::matched($own, $words),
                self::matched($words, $own) === count($own) ? 1 : 0,
                self::same($own[0], $words[0]) ? 1 : 0,
                (int) $record['n'],
            ];

            if ($bestKey === null || $key > $bestKey) {
                $best = $record;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /**
     * Products whose title holds the object's head noun, the ones holding more of its words first.
     *
     * @param  list<list<string>>  $words
     * @return list<string> external ids
     */
    private function products(array $words): array
    {
        $found = [];

        foreach ($this->index['records'] as $id => $record) {
            if (($record['t'] ?? null) !== 'product') {
                continue;
            }

            $own = $this->wordsOf((string) $id, (string) $record['title']);

            if ($own !== [] && self::holds($own, $words[0])) {
                $found[substr((string) $id, 2)] = self::matched($own, $words) * 10 + (self::same($own[0], $words[0]) ? 1 : 0);
            }
        }

        arsort($found);

        return array_map('strval', array_slice(array_keys($found), 0, self::PRODUCTS));
    }

    /**
     * The categories the products share, the most shared first; among those nearly as shared as
     * the top one, the smallest (the most specific) first. Nothing when the products agree on
     * no category, unless there is only one product.
     *
     * @param  list<string>  $externalIds
     * @return list<string> category external ids
     */
    private function vote(array $externalIds): array
    {
        $counts = [];

        foreach ($this->categoriesOf($externalIds) as $categories) {
            foreach ($categories as $category) {
                if ($this->record(self::CATEGORY.$category) !== null) {
                    $counts[$category] = ($counts[$category] ?? 0) + 1;
                }
            }
        }

        if ($counts === [] || (max($counts) < 2 && count($externalIds) > 1)) {
            return [];
        }

        $max = max($counts);
        $size = fn (string $id): int => (int) ($this->record(self::CATEGORY.$id)['n'] ?? PHP_INT_MAX);
        uksort($counts, function (string $a, string $b) use ($counts, $max, $size): int {
            $near = $counts[$a] >= self::NEAR_SHARE * $max && $counts[$b] >= self::NEAR_SHARE * $max;

            return $near
                ? ($size($a) <=> $size($b) ?: $counts[$b] <=> $counts[$a] ?: strcmp($a, $b))
                : ($counts[$b] <=> $counts[$a] ?: strcmp($a, $b));
        });

        return array_map('strval', array_keys($counts));
    }

    /** @return list<list<string>> */
    private function wordsOf(string $id, string $title): array
    {
        return $this->words[$id] ??= self::words($title);
    }

    /**
     * How many of the wanted words the phrase holds.
     *
     * @param  list<list<string>>  $words
     * @param  list<list<string>>  $wanted
     */
    private static function matched(array $words, array $wanted): int
    {
        $count = 0;

        foreach ($wanted as $want) {
            $count += self::holds($words, $want) ? 1 : 0;
        }

        return $count;
    }

    /**
     * @param  list<list<string>>  $words
     * @param  list<string>  $forms
     */
    private static function holds(array $words, array $forms): bool
    {
        foreach ($words as $word) {
            if (self::same($word, $forms)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function same(array $a, array $b): bool
    {
        return array_intersect($a, $b) !== [];
    }
}
