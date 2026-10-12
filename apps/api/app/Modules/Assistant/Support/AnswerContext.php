<?php

namespace App\Modules\Assistant\Support;

use App\Core\Facades\Settings;
use App\Modules\Retrieval\Contracts\Passages;

/**
 * What the writing model is given for one question in the search box. In full mode, everything
 * found, as before. In dynamic mode, the products and passages this kind of question needs, and of
 * each product the fields it asks about: fewer tokens, the same answer.
 *
 * Products are scored on three things: how near their text is to the question by meaning (the
 * question's vector is already made for the passage search, so this costs no call), how many of the
 * question's words they hold, and how high the shopper was shown them. They are kept down to the
 * biggest drop in score, above a share of the best; the first ones shown and any product the
 * question names are always kept. Order is never changed: what is kept goes in the order shown.
 *
 * Questions about price (the cheapest, "עד 2000", "כמה עולה") get the whole set as before: they are
 * to be answered in code from the catalog, not narrowed for a model.
 */
final class AnswerContext
{
    public const FULL = 'full';

    public const DYNAMIC = 'dynamic';

    public const OTHER = 'other';

    /** Kinds of question given everything, as in full mode. */
    public const WHOLE_SET = ['superlative', 'price_range', 'price_exact', 'recommendation', 'comparison', self::OTHER];

    /** Kinds about products, where the store's general text (shipping, returns) is not needed. */
    public const PRODUCT_KINDS = ['size', 'stock', 'attribute'];

    /** Product passages near the question are looked up this deep, to score the products shown. */
    private const NEAR_PRODUCTS = 60;

    /** A drop in score this large between two products ends the list. */
    private const DROP = 0.15;

    /** A page found by meaning stays when it scores this close to the best page found. */
    private const PASSAGE_RATIO = 0.85;

    private const WEIGHTS = ['semantic' => 0.5, 'lexical' => 0.3, 'position' => 0.2];

    /** Without any meaning score (no vectors for these products), words and position decide. */
    private const WEIGHTS_WITHOUT_MEANING = ['semantic' => 0.0, 'lexical' => 0.6, 'position' => 0.4];

    public function __construct(private readonly Passages $passages) {}

    /** Hebrew final letters as their regular forms: "יהלום" is in "יהלומים", "אבן" in "אבנים". */
    private const FINALS = ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ'];

    /** The kind of question, from the patterns in resources/question-types.json; the first that matches. */
    public static function type(string $question): string
    {
        $config = self::config();
        $text = strtr(mb_strtolower($question), self::FINALS);

        foreach ((array) $config['order'] as $type) {
            if (self::matches($text, (array) ($config['types'][$type] ?? []))) {
                return $type;
            }
        }

        return self::OTHER;
    }

    /**
     * What else the question limits, whatever its kind: "מתנה לאמא עד 1500" is a recommendation
     * with a price. A price limit is for code to apply against the catalog, not for a model.
     *
     * @return list<string>
     */
    public static function constraints(string $question): array
    {
        $text = strtr(mb_strtolower($question), self::FINALS);

        return array_keys(array_filter((array) (self::config()['constraints'] ?? []), fn (array $patterns): bool => self::matches($text, $patterns)));
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        static $config = null;

        return $config ??= (array) json_decode((string) file_get_contents(dirname(__DIR__).'/resources/question-types.json'), true);
    }

    /** @param list<string> $patterns */
    private static function matches(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match('~'.strtr($pattern, self::FINALS).'~iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $products  the products shown, in the order shown
     * @param  list<array<string, mixed>>  $passages  as found: knowledge first, then pages by title, then by meaning
     * @return array{products: list<array<string, mixed>>, passages: list<array<string, mixed>>, debug: array{products: list<array<string, mixed>>, passages: list<array<string, mixed>>}}
     */
    public function select(string $shopId, string $question, string $type, array $products, array $passages): array
    {
        $scored = $this->score($shopId, $question, $products);
        [$keptProducts, $productDebug] = $this->products($shopId, $question, $type, $scored, $passages !== []);
        $kept = array_flip(array_column($keptProducts, 'external_id'));
        [$keptPassages, $passageDebug] = $this->passages($question, $type, $passages, $kept);

        return ['products' => $keptProducts, 'passages' => $keptPassages, 'debug' => ['products' => $productDebug, 'passages' => $passageDebug]];
    }

    /**
     * The fields of one product this kind of question needs. The model's full entry (as forModel
     * builds it) for kinds given everything; for the others, a few always and the ones asked about.
     *
     * @param  array<string, mixed>  $entry  what the model is given in full mode
     * @param  array<string, mixed>  $product  the product as candidates() read it, with its options and measures
     * @return array<string, mixed>
     */
    public static function fields(string $type, array $entry, array $product, string $question): array
    {
        if (! in_array($type, [...self::PRODUCT_KINDS, 'store'], true)) {
            return $entry;
        }

        $base = array_intersect_key($entry, array_flip(['ref', 'title', 'in_stock']));
        $words = Question::words($question);
        $about = (string) ($entry['about'] ?? '');
        $mentions = $about !== '' && array_filter($words, fn (string $w): bool => mb_stripos($about, $w) !== false) !== [];

        $extra = match ($type) {
            'size' => [
                'sizes' => $product['sizes'] ?? [],
                'measures' => $product['measures'] ?? [],
                // Measured options besides the sizes, which are given once, as sizes.
                'attributes' => array_values(array_filter((array) ($product['attributes'] ?? []), fn (string $a): bool => preg_match('/\d/', $a) === 1 && preg_match('/^(size|מיד|גודל)/iu', $a) !== 1)),
                'about' => preg_match('/\d/', $about) === 1 ? $about : null,
            ],
            'stock' => [
                'options_in_stock' => $product['options_in_stock'] ?? [],
                'sizes' => $product['sizes'] ?? [],
            ],
            'attribute' => [
                'category' => $entry['category'] ?? null,
                'attributes' => $product['attributes'] ?? [],
                'about' => $mentions ? $about : null,
            ],
            default => [],
        };

        return $base + array_filter($extra, fn ($v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>> each product with its scores and position
     */
    private function score(string $shopId, string $question, array $products): array
    {
        if ($products === []) {
            return [];
        }

        $near = [];
        foreach ($this->passages->near($shopId, $question, ['product'], self::NEAR_PRODUCTS) as $passage) {
            $id = (string) ($passage['external_id'] ?? '');
            $near[$id] = max($near[$id] ?? 0.0, (float) $passage['similarity']);
        }

        $shownNear = array_intersect_key($near, array_flip(array_map(fn (array $p): string => (string) $p['external_id'], $products)));
        $best = $shownNear === [] ? 0.0 : max($shownNear);
        $weights = $best > 0 ? self::WEIGHTS : self::WEIGHTS_WITHOUT_MEANING;
        $words = Question::words($question);

        foreach ($products as $i => $product) {
            $hay = mb_strtolower(implode(' ', [$product['title'], $product['category'] ?? '', implode(' ', (array) ($product['attributes'] ?? [])), $product['about'] ?? '']));
            $semantic = $best > 0 ? ($near[(string) $product['external_id']] ?? 0.0) / $best : 0.0;
            $lexical = $words === [] ? 0.0 : count(array_filter($words, fn (string $w): bool => mb_strpos($hay, $w) !== false)) / count($words);
            $position = 1 / (1 + $i);

            $products[$i] += [
                'position' => $i + 1,
                'semantic' => round($semantic, 3),
                'lexical' => round($lexical, 3),
                'position_score' => round($position, 3),
                'final' => round($weights['semantic'] * $semantic + $weights['lexical'] * $lexical + $weights['position'] * $position, 3),
                'named' => self::named($question, $product, $words),
            ];
        }

        return $products;
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function products(string $shopId, string $question, string $type, array $scored, bool $hasPassages): array
    {
        $reasons = [];

        if ($type === 'store') {
            foreach ($scored as $p) {
                $reasons[$p['external_id']] = match (true) {
                    $p['named'] => [true, 'named_in_question'],
                    ! $hasPassages => [true, 'store_question_without_passages'],
                    default => [false, 'store_question'],
                };
            }
        } elseif (in_array($type, self::WHOLE_SET, true)) {
            foreach ($scored as $p) {
                $reasons[$p['external_id']] = [true, 'whole_set_for_'.$type];
            }
        } else {
            $floor = (int) Settings::get('assistant.context_min_products', $shopId);
            $ratio = (float) Settings::get('assistant.context_keep_ratio', $shopId);
            $byScore = $scored;
            usort($byScore, fn (array $a, array $b): int => $b['final'] <=> $a['final']);
            $best = (float) ($byScore[0]['final'] ?? 0);

            // Above the share of the best score, then down to the biggest drop between two in a row.
            $above = array_values(array_filter($byScore, fn (array $p): bool => $p['final'] >= $ratio * $best));
            $cut = count($above);
            $biggest = 0.0;
            for ($i = 1; $i < count($above); $i++) {
                $drop = $above[$i - 1]['final'] - $above[$i]['final'];
                if ($drop >= self::DROP && $drop > $biggest) {
                    $biggest = $drop;
                    $cut = $i;
                }
            }
            $byDrop = array_flip(array_map(fn (array $p): string => (string) $p['external_id'], array_slice($above, 0, $cut)));

            foreach ($scored as $p) {
                $id = (string) $p['external_id'];
                $reasons[$id] = match (true) {
                    $p['position'] <= $floor => [true, 'shown_first'],
                    $p['named'] => [true, 'named_in_question'],
                    isset($byDrop[$id]) => [true, 'score'],
                    $p['final'] < $ratio * $best => [false, 'below_share_of_best'],
                    default => [false, 'after_biggest_drop'],
                };
            }
        }

        $kept = [];
        $debug = [];
        foreach ($scored as $p) {
            [$keep, $why] = $reasons[(string) $p['external_id']];
            $debug[] = array_intersect_key($p, array_flip(['external_id', 'title', 'position', 'semantic', 'lexical', 'position_score', 'final'])) + ['kept' => $keep, 'reason' => $why];

            if ($keep) {
                $kept[] = array_diff_key($p, array_flip(['position', 'semantic', 'lexical', 'position_score', 'final', 'named']));
            }
        }

        return [$kept, $debug];
    }

    /**
     * @param  list<array<string, mixed>>  $passages
     * @param  array<string, int>  $keptProducts  external ids kept
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function passages(string $question, string $type, array $passages, array $keptProducts): array
    {
        $words = Question::words($question);
        $pages = array_filter($passages, fn (array $p): bool => ($p['kind'] ?? 'meaning') === 'meaning' && $p['source'] === 'content');
        $bestPage = $pages === [] ? 0.0 : max(array_map(fn (array $p): float => (float) $p['similarity'], $pages));
        $kept = [];
        $debug = [];

        foreach ($passages as $p) {
            $kind = $p['kind'] ?? 'meaning';
            $titled = array_filter($words, fn (string $w): bool => mb_stripos((string) $p['title'], $w) !== false) !== [];

            [$keep, $why] = match (true) {
                ! in_array($type, self::PRODUCT_KINDS, true) => [true, $type === 'store' ? 'store_question' : 'whole_set_for_'.$type],
                $kind === 'knowledge' => $titled ? [true, 'knowledge_titled_by_question'] : [false, 'store_text_not_needed_for_product_question'],
                $kind === 'title' => [true, 'title_matches_question'],
                $p['source'] === 'product' => isset($keptProducts[(string) ($p['external_id'] ?? '')]) ? [true, 'product_kept'] : [false, 'product_not_kept'],
                (float) $p['similarity'] >= self::PASSAGE_RATIO * $bestPage => [true, 'near_the_best_page'],
                default => [false, 'far_below_the_best_page'],
            };

            $debug[] = ['title' => $p['title'], 'kind' => $kind, 'source' => $p['source'], 'similarity' => round((float) $p['similarity'], 3), 'chars' => mb_strlen((string) $p['text']), 'kept' => $keep, 'reason' => $why];

            if ($keep) {
                $kept[] = $p;
            }
        }

        return [$kept, $debug];
    }

    /**
     * Whether the question names this product: its SKU, or every word of the question in its title.
     * A word only some titles share ("טבעת") names none of them.
     *
     * @param  array<string, mixed>  $product
     * @param  list<string>  $words
     */
    private static function named(string $question, array $product, array $words): bool
    {
        $sku = trim((string) ($product['sku'] ?? ''));

        if ($sku !== '' && mb_strlen($sku) >= 3 && mb_stripos($question, $sku) !== false) {
            return true;
        }

        $title = mb_strtolower((string) $product['title']);

        return $words !== [] && array_filter($words, fn (string $w): bool => mb_strpos($title, $w) === false) === [];
    }
}
