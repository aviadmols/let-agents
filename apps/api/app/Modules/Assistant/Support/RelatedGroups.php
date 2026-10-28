<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use Illuminate\Support\Facades\Cache;

/**
 * The store's own categories and tags a question is about, found by code in what its products say: their titles,
 * descriptions, tags and categories. "תראה לי טבעות נישואין" finds the rings whose descriptions say נישואין, and so
 * "טבעת סוליטר" and "טבעת עיגול חלקה", where the store files them, though no category is named for weddings. Its
 * products join the answer's candidates, and its groups are offered as chips under the answer. Nothing is tagged:
 * a product added tomorrow that says the same is found the same way. Code only, inside the shop.
 */
final class RelatedGroups
{
    /** The most groups offered under an answer. */
    public const MAX = 8;

    /** The most of their products given to the answer. */
    public const PRODUCTS = 12;

    /** A group is the question's when at least this many of its products say every word of it… */
    private const MIN_HITS = 2;

    /** …and at least this share of the group does: "טבעות" holding a few wedding rings among hundreds is not it. */
    private const MIN_SHARE = 0.2;

    /** Words that ask, not name ("תראה לי", "מחפשת", "מומלצות"). */
    private const FILLER = [
        'מה', 'מהי', 'מהו', 'איך', 'האם', 'יש', 'אפשר', 'כמה', 'מתי', 'למה', 'של', 'את', 'על', 'עם', 'לי', 'לכם', 'שלכם', 'אצלכם', 'איזה', 'איזו', 'אילו',
        'תראה', 'תראי', 'תראו', 'הראה', 'תציג', 'תציגי', 'תציגו', 'הצג', 'תן', 'תני', 'תמליץ', 'תמליצי', 'תמליצו', 'ממליץ', 'ממליצה', 'ממליצים', 'מומלץ', 'מומלצת',
        'מומלצים', 'מומלצות', 'מחפש', 'מחפשת', 'מחפשים', 'רוצה', 'רוצים', 'צריך', 'צריכה', 'צריכים', 'אשמח', 'בבקשה', 'לקנות', 'קונה', 'הכי', 'טוב', 'טובה',
        'טובים', 'טובות', 'יפה', 'יפים', 'יפות', 'משהו', 'כל', 'עוד', 'גם', 'או', 'זה', 'זו', 'אלה', 'שיש', 'עבור', 'בשביל',
        'what', 'which', 'is', 'are', 'the', 'how', 'do', 'does', 'you', 'your', 'can', 'have', 'any', 'some', 'show', 'me', 'looking', 'look', 'want',
        'need', 'recommend', 'please', 'best', 'good', 'nice', 'find', 'buy', 'for', 'and', 'with',
    ];

    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    /** @var array<string, array{groups: list<array{kind: string, id: string, name: string, count: int}>, ids: list<string>}> */
    private static array $found = [];

    /**
     * The groups a question is about, the closest first, and their products that say it, in stock first, across
     * the prices. Nothing when the question's words name nothing the store's products say.
     *
     * @return array{groups: list<array{kind: string, id: string, name: string, count: int}>, ids: list<string>}
     */
    public static function for(string $shopId, string $question): array
    {
        $key = $shopId.'.'.Question::key($question);

        return self::$found[$key] ??= Cache::remember('assistant.related.'.$key, 3600, fn (): array => self::find($question));
    }

    /** @return array{groups: list<array{kind: string, id: string, name: string, count: int}>, ids: list<string>} */
    private static function find(string $question): array
    {
        $none = ['groups' => [], 'ids' => []];
        $words = self::words($question);
        if ($words === []) {
            return $none;
        }

        $said = array_fill_keys(array_keys($words), 0);
        $products = [];
        $size = [];
        $total = 0;
        CatalogProduct::query()->active()->with('categories:id,external_id,name')
            ->select(['id', 'external_id', 'title', 'price', 'in_stock', 'payload'])
            ->chunkById(500, function ($chunk) use ($words, &$said, &$products, &$size, &$total): void {
                foreach ($chunk as $product) {
                    $total++;
                    $groups = [];
                    foreach ($product->categories as $category) {
                        $groups['c:'.$category->external_id] = (string) $category->name;
                    }
                    foreach ((array) ($product->payload['tags'] ?? []) as $tag) {
                        $tag = (string) preg_replace('/^\s+|\s+$/u', '', (string) $tag);
                        if ($tag !== '') {
                            $groups['t:'.mb_strtolower($tag)] = $tag;
                        }
                    }
                    foreach (array_keys($groups) as $g) {
                        $size[$g] = ($size[$g] ?? 0) + 1;
                    }
                    $text = self::searchable(implode(' ', [$product->title, strip_tags($product->shortDescription()), strip_tags($product->description()), ...array_values($groups)]));
                    $says = array_keys(array_filter($words, fn (array $forms): bool => self::says($text, $forms)));
                    foreach ($says as $w) {
                        $said[$w]++;
                    }
                    if ($says !== []) {
                        $products[] = ['id' => (string) $product->external_id, 'price' => $product->price, 'in_stock' => (bool) $product->in_stock, 'says' => $says, 'groups' => $groups];
                    }
                }
            });

        // a word no product says is not about the catalog ("שלי"); every other word is asked of each product
        $needed = array_keys(array_filter($said));
        if ($needed === []) {
            return $none;
        }
        $hits = array_values(array_filter($products, fn (array $p): bool => array_diff($needed, $p['says']) === []));

        $count = [];
        $names = [];
        foreach ($hits as $p) {
            foreach ($p['groups'] as $g => $name) {
                $count[$g] = ($count[$g] ?? 0) + 1;
                $names[$g] = $name;
            }
        }
        $least = count($hits) <= 2 ? 1 : self::MIN_HITS;
        $groups = [];
        foreach ($count as $g => $n) {
            $share = $n / $size[$g];
            // a group of the whole store says nothing of the question
            if ($n < $least || $share < self::MIN_SHARE || $size[$g] >= $total) {
                continue;
            }
            // a name saying the whole question ("טבעות נישואין") first
            $name = self::searchable($names[$g]);
            $named = array_filter($needed, fn (string $w): bool => ! self::says($name, $words[$w])) === [];
            $groups[] = ['g' => $g, 'named' => $named, 'rank' => $n * $share, 'n' => $n];
        }
        usort($groups, fn (array $a, array $b): int => [$b['named'], $b['rank'], $a['g']] <=> [$a['named'], $a['rank'], $b['g']]);

        // one name once: a category and a tag of the same name are one chip, the category
        $out = [];
        $seen = [];
        foreach ($groups as $group) {
            $name = $names[$group['g']];
            $lower = mb_strtolower($name);
            if (isset($seen[$lower])) {
                continue;
            }
            $seen[$lower] = true;
            $out[$group['g']] = ['kind' => str_starts_with($group['g'], 'c:') ? 'category' : 'tag', 'id' => substr($group['g'], 2), 'name' => $name, 'count' => $group['n']];
            if (count($out) === self::MAX) {
                break;
            }
        }
        if ($out === []) {
            return $none;
        }

        // the products that say it inside those groups, in stock first, across the prices
        $inside = array_values(array_filter($hits, fn (array $p): bool => array_intersect_key($p['groups'], $out) !== []));
        $ids = [
            ...QuestionLinks::spread(array_values(array_filter($inside, fn (array $p): bool => $p['in_stock'])), self::PRODUCTS),
            ...QuestionLinks::spread(array_values(array_filter($inside, fn (array $p): bool => ! $p['in_stock'])), self::PRODUCTS),
        ];

        return ['groups' => array_values($out), 'ids' => array_slice(array_values(array_unique($ids)), 0, self::PRODUCTS)];
    }

    /**
     * The words of a question that may name something, each with the forms a product's text may hold it in: as
     * written, without a plural ending, without a leading ו ה ב ל מ ש כ, final letters as any other, and dry (ו and
     * י dropped after the first letter, so "טבעות" meets "טבעת").
     *
     * @return array<string, list<string>>
     */
    private static function words(string $question): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [] as $word) {
            if (mb_strlen($word) < 2 || in_array($word, self::FILLER, true) || preg_match('/^\d+$/', $word) === 1) {
                continue;
            }
            $plain = strtr($word, self::FINALS);
            $forms = [$plain];
            if (mb_strlen($plain) > 4) {
                $forms[] = (string) preg_replace('/(ימ|ות)$/u', '', $plain);
            }
            if (mb_strlen($plain) > 3 && preg_match('/^[הובלמשכ]/u', $plain) === 1) {
                $forms[] = mb_substr($plain, 1);
            }
            $out[$plain] = array_values(array_unique(array_map(self::dry(...), $forms)));
        }

        return $out;
    }

    /** A text as its words are searched: each dry, and again without a leading letter, between spaces. */
    private static function searchable(string $text): string
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', strtr(mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')), self::FINALS)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $words[] = self::dry($word);
            if (mb_strlen($word) > 3 && preg_match('/^[הובלמשכ]/u', $word) === 1) {
                $words[] = self::dry(mb_substr($word, 1));
            }
        }

        return ' '.implode(' ', array_unique($words)).' ';
    }

    /** Whether the text holds a word in one of its forms: as a word's start when the form is long enough to say it. */
    private static function says(string $text, array $forms): bool
    {
        foreach ($forms as $form) {
            if (mb_strlen($form) >= 3 ? str_contains($text, ' '.$form) : str_contains($text, ' '.$form.' ')) {
                return true;
            }
        }

        return false;
    }

    /** The word without ו and י after its first letter: "טבעות" → "טבעת". */
    private static function dry(string $word): string
    {
        return mb_substr($word, 0, 1).str_replace(['ו', 'י'], '', mb_substr($word, 1));
    }
}
