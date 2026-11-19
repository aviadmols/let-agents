<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;

/**
 * What a store's catalogue says, counted in code with no knowledge of any trade: how many products there are, the
 * language they are written in, the store's own fields with how many products carry each value (SchemaStats), the
 * words and the phrases the products repeat with their counts, and a sample of products spread over every kind the
 * store sells. No ready vocabulary is read and none is counted against: this page is all the expert bank is written
 * from (BuildExpertBank).
 *
 * Beside the page, each product's text as code reads it, so the bank is measured on the very words it was written from.
 *
 * Every list is sorted explicitly (count, then text) and the products come in the order of their ids in the store, so
 * the same catalogue gives the same page and the same fingerprint.
 */
final class CatalogueFacts
{
    public const TOP_WORDS = 250;

    public const TOP_PHRASES = 150;

    /** A word or a phrase is listed when at least this many products say it. */
    public const MIN_PRODUCTS = 2;

    /** The start of a description read for words and matched for values, as code reads it elsewhere. */
    public const HEAD = 600;

    /** The start of a description shown with a sampled product. */
    private const SAMPLE_DESCRIPTION = 300;

    /** Parts of a product's text are kept apart, so no phrase is read across two fields. */
    private const BETWEEN = ' ; ';

    /** Words of the language itself, never of a trade: joining words that say nothing of a product. */
    private const STOP = [
        'של', 'עם', 'את', 'על', 'או', 'גם', 'כל', 'לא', 'זה', 'זו', 'הוא', 'היא', 'אל', 'מן', 'כי', 'אם', 'יש', 'אין', 'עד', 'כמו', 'רק', 'בין', 'אך', 'אבל', 'כדי', 'לכל', 'בכל', 'אשר', 'הם', 'הן',
        'the', 'and', 'with', 'for', 'of', 'in', 'to', 'a', 'an', 'on', 'at', 'by', 'or', 'is', 'are', 'it', 'its', 'this', 'that', 'from', 'as', 'be', 'our', 'your', 'you', 'we', 'will', 'can', 'has', 'have', 'not', 'all', 'any',
    ];

    /**
     * @param  iterable<CatalogProduct>  $products  in a fixed order
     * @param  int  $sample  how many products are shown whole
     * @param  string  $locale  the language the store says its content is in
     * @return array{facts: array<string, mixed>, texts: list<array{id: string, title: string, head: string, full: string}>}
     */
    public static function of(iterable $products, int $sample, string $locale): array
    {
        $texts = [];
        $rows = [];
        $marks = [];
        $order = [];
        $words = [];
        $phrases = [];
        $letters = ['he' => 0, 'latin' => 0];
        $stop = array_flip(self::STOP);

        // One pass: SchemaStats counts the store's own fields from the same products as they go by.
        $seen = function () use ($products, &$texts, &$rows, &$marks, &$order, &$words, &$phrases, &$letters, $stop) {
            foreach ($products as $product) {
                $id = (string) $product->id;
                $title = TextNormalizer::clean((string) $product->title);
                $type = self::tidy((string) ($product->payload['product_type'] ?? ''));
                $tags = array_values(array_unique(array_filter(array_map(fn ($t): string => self::tidy((string) $t), (array) ($product->payload['tags'] ?? [])), fn (string $t): bool => $t !== '')));
                // a tag written as a path ("Women > Sandals > Elegant Collection") is a category in all but name: its parts say
                // what the product is, as a category does (Naot, 19.11: every type found no evidence, the kinds were in such tags)
                $paths = array_values(array_filter($tags, fn (string $t): bool => str_contains($t, ' > ')));
                $tags = array_values(array_diff($tags, $paths));
                $fromPaths = array_merge(...array_map(fn (string $t): array => array_map(fn (string $p): string => self::tidy($p), explode(' > ', $t)), $paths ?: ['']));
                $categories = array_values(array_unique(array_filter([...array_map(fn (array $path): string => self::tidy((string) end($path)), array_filter($product->categoryPaths())), ...$fromPaths], fn (string $c): bool => $c !== '')));
                $attributes = [];
                foreach ($product->storeAttributes() as $attribute) {
                    $name = self::tidy($attribute['name']);
                    if ($name !== '') {
                        $attributes[$name] = array_values(array_unique(array_map(fn (string $v): string => self::tidy($v), $attribute['values'])));
                    }
                }
                ksort($attributes, SORT_STRING);
                $description = TextNormalizer::clean(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $product->description())));

                // The title, the store's type, its categories and its tags say what a product is (Naot, 19.11: "Backstrap
                // Sandal" was a tag on 53 products whose titles are model names); the rest says what it is like. A tag that
                // misfiles a product is the type audit's to catch, as a category that does.
                $fields = implode(self::BETWEEN, array_filter([$title, $type, ...$categories, ...$tags], fn (string $part): bool => $part !== ''));
                $own = implode(self::BETWEEN, [...$tags, ...array_map(fn (string $name, array $values): string => $name.': '.implode(', ', $values), array_keys($attributes), $attributes)]);
                $head = TextNormalizer::forMatching($fields);
                $full = TextNormalizer::forMatching($fields.self::BETWEEN.$own.self::BETWEEN.mb_substr($description, 0, self::HEAD));

                $texts[] = ['id' => $id, 'title' => mb_substr($title, 0, 160), 'head' => $head, 'full' => $full];
                $rows[$id] = [
                    'id' => (string) $product->external_id,
                    'title' => mb_substr($title, 0, 160),
                    'type' => $type,
                    'categories' => array_slice($categories, 0, 4),
                    'tags' => array_slice($tags, 0, 10),
                    'attributes' => array_slice(array_map(fn (array $values): array => array_slice($values, 0, 8), $attributes), 0, 8, true),
                    'description' => mb_substr((string) preg_replace('/\s+/u', ' ', $description), 0, self::SAMPLE_DESCRIPTION),
                ];
                $marks[$id] = [$product->kindMarks(), (bool) $product->in_stock];
                $order[] = [crc32((string) $product->external_id), $id];

                $letters['he'] += (int) preg_match_all('/\p{Hebrew}/u', $title);
                $letters['latin'] += (int) preg_match_all('/\p{Latin}/u', $title);

                // Words from everything code reads of the product; phrases from the title and the store's own fields,
                // where the store names things. Each once a product.
                foreach (array_unique(self::tokens($full, $stop)) as $word) {
                    $words[$word] = ($words[$word] ?? 0) + 1;
                }
                $said = [];
                foreach (explode(trim(self::BETWEEN), TextNormalizer::forMatching($fields.self::BETWEEN.$own)) as $part) {
                    $tokens = self::tokens($part, []);
                    for ($size = 2; $size <= 3; $size++) {
                        for ($i = 0; $i + $size <= count($tokens); $i++) {
                            // A phrase starts and ends with a word that says something.
                            if (! isset($stop[$tokens[$i]]) && ! isset($stop[$tokens[$i + $size - 1]])) {
                                $said[implode(' ', array_slice($tokens, $i, $size))] = true;
                            }
                        }
                    }
                }
                foreach (array_keys($said) as $phrase) {
                    $phrases[(string) $phrase] = ($phrases[(string) $phrase] ?? 0) + 1;
                }

                yield $product;
            }
        };

        $stats = SchemaStats::collect($seen());
        $n = count($texts);

        if ($n === 0) {
            return ['facts' => ['products' => 0], 'texts' => []];
        }

        return [
            'facts' => [
                'products' => $n,
                'language' => self::language($letters, $locale),
                'fields' => $stats['fields'],
                'attributes' => $stats['attributes'],
                'spec_fields' => $stats['spec_fields'],
                'key_values' => $stats['key_values'],
                'words' => self::top($words, self::TOP_WORDS),
                'phrases' => self::top($phrases, self::TOP_PHRASES),
                'sample' => array_map(fn (string $id): array => $rows[$id], self::sample($marks, $order, $sample)),
            ],
            'texts' => $texts,
        ];
    }

    /**
     * A text's words, lower case, in their order: letters and digits, with the marks Hebrew writes inside a word.
     *
     * @param  array<string, int>  $stop  words left out
     * @return list<string>
     */
    public static function tokens(string $text, array $stop): array
    {
        return array_values(array_filter(
            preg_split('/[^\p{L}\p{N}\'"]+/u', mb_strtolower($text)) ?: [],
            fn (string $word): bool => mb_strlen($word) >= 2 && ! isset($stop[$word]) && preg_match('/^[\d\'"]+$/u', $word) !== 1,
        ));
    }

    /** Hebrew when the titles are written mostly in Hebrew letters; otherwise the store's own language, or English. */
    private static function language(array $letters, string $locale): string
    {
        if ($letters['he'] > $letters['latin']) {
            return 'he';
        }

        return $locale !== '' && $locale !== 'he' ? $locale : 'en';
    }

    /**
     * Product ids spread over the kinds the store sells: first the fewest that show every kind (CatalogProduct::cover),
     * then the rest by a hash of the store's id, the same ones every time.
     *
     * @param  array<string, array{0: list<string>, 1: bool}>  $marks
     * @param  list<array{0: int, 1: string}>  $order
     * @return list<string>
     */
    private static function sample(array $marks, array $order, int $size): array
    {
        $out = array_map('strval', CatalogProduct::cover($marks, $size)[0]);
        $taken = array_flip($out);
        sort($order);

        foreach ($order as [, $id]) {
            if (count($out) >= $size) {
                break;
            }
            if (! isset($taken[$id])) {
                $out[] = $id;
            }
        }

        return array_slice($out, 0, $size);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int> said by at least MIN_PRODUCTS, the most common first, then by text
     */
    private static function top(array $counts, int $limit): array
    {
        $counts = array_filter($counts, fn (int $c): bool => $c >= self::MIN_PRODUCTS);
        uksort($counts, fn (int|string $a, int|string $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        return array_slice($counts, 0, $limit, true);
    }

    /** One line with single spaces and nothing around it; never trim(): the text is Hebrew as often as not. */
    private static function tidy(string $text): string
    {
        return (string) preg_replace('/^\s+|\s+$/u', '', (string) preg_replace('/\s+/u', ' ', TextNormalizer::clean($text)));
    }
}
