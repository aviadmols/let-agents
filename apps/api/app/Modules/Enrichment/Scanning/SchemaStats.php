<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * What a sample of a store's products hold, counted in code, so a model proposing the store's
 * vocabulary reads a page of numbers instead of thousands of products: the store's own fields
 * (type, tags, categories, attributes, spec fields) and how many products carry each value; every
 * number with a unit in the titles and descriptions, with the words before it ("משקל התכשיט",
 * "בסך הכל"); the "key: value" lines of the descriptions; the words the titles repeat; the words
 * of each picture's description (colours, shapes, materials); and how well each ready
 * vocabulary's product types fit the titles.
 *
 * Every list is sorted explicitly (count, then text), so the same products give the same page,
 * and its fingerprint stays the same.
 */
final class SchemaStats
{
    private const TOP_VALUES = 20;

    private const TOP_TAGS = 40;

    private const TOP_KEYS = 25;

    private const TOP_WORDS = 60;

    private const TOP_PAIRS = 30;

    private const TOP_CONTEXTS = 8;

    private const EXAMPLES = 8;

    /** The start of a description read for measurements and "key: value" lines. */
    private const HEAD = 1500;

    /** A word or a pair must be in this share of the titles to be listed. */
    private const MIN_SHARE = 0.03;

    private const STOP = ['של', 'עם', 'את', 'על', 'או', 'גם', 'כל', 'לא', 'זה', 'זו', 'the', 'and', 'with', 'for', 'of', 'in', 'to', 'a'];

    /**
     * @param  iterable<CatalogProduct>  $products
     * @param  array<string, VocabularyDefinition>  $templates  by name
     * @param  array<string, array{caption: string, words: list<string>}>  $pictures  each product's picture as a model described it, by product id
     * @return array<string, mixed>
     */
    public static function collect(iterable $products, array $templates = [], array $pictures = []): array
    {
        $scanner = new MeasurementScanner(40);
        $n = 0;
        $fields = ['product_type' => [], 'tags' => [], 'categories' => []];
        $carriers = ['product_type' => 0, 'tags' => 0, 'categories' => 0];
        $attributes = [];
        $specFields = [];
        $measurements = [];
        $keyValues = [];
        $words = [];
        $pairs = [];
        $fits = array_fill_keys(array_keys($templates), 0);
        $pictureWords = [];
        $described = 0;
        $examples = [];

        foreach ($products as $product) {
            $n++;
            $title = TextNormalizer::clean((string) $product->title);
            $type = trim((string) ($product->payload['product_type'] ?? ''));
            $tags = array_values(array_unique(array_filter(array_map(fn ($t): string => trim((string) $t), (array) ($product->payload['tags'] ?? [])))));
            $leaves = array_values(array_unique(array_filter(array_map(fn (array $path): string => (string) end($path), array_filter($product->categoryPaths())))));
            $head = mb_substr(TextNormalizer::clean(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $product->description()))), 0, self::HEAD);

            foreach (['product_type' => $type === '' ? [] : [$type], 'tags' => $tags, 'categories' => $leaves] as $field => $values) {
                $carriers[$field] += $values === [] ? 0 : 1;
                foreach ($values as $value) {
                    $fields[$field][$value] = ($fields[$field][$value] ?? 0) + 1;
                }
            }

            foreach ($product->storeAttributes() as $attribute) {
                $name = trim($attribute['name']);
                if ($name === '') {
                    continue;
                }
                $attributes[$name]['n'] = ($attributes[$name]['n'] ?? 0) + 1;
                $attributes[$name]['for_variations'] = ($attributes[$name]['for_variations'] ?? false) || $attribute['for_variations'];
                foreach (array_unique($attribute['values']) as $value) {
                    $attributes[$name]['values'][$value] = ($attributes[$name]['values'][$value] ?? 0) + 1;
                }
            }

            foreach ($product->specFields() as $line) {
                [$key, $value] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
                if ($key !== '') {
                    $specFields[$key]['n'] = ($specFields[$key]['n'] ?? 0) + 1;
                    $specFields[$key]['values'][$value] = ($specFields[$key]['values'][$value] ?? 0) + 1;
                }
            }

            // Numbers with units, each dimension counted once a product, with the two words before it.
            $seen = [];
            foreach ($scanner->scan($title."\n".$head) as $m) {
                $d = $m->dimension;
                $measurements[$d] ??= ['n' => 0, 'min' => $m->value, 'max' => $m->value, 'units' => [], 'contexts' => [], 'examples' => []];
                if (! isset($seen[$d])) {
                    $measurements[$d]['n']++;
                    $seen[$d] = true;
                }
                $measurements[$d]['min'] = min($measurements[$d]['min'], $m->value);
                $measurements[$d]['max'] = max($measurements[$d]['max'], $m->value);
                $measurements[$d]['units'][$m->unit] = ($measurements[$d]['units'][$m->unit] ?? 0) + 1;
                $context = self::before($title."\n".$head, $m->raw);
                if ($context !== '') {
                    $measurements[$d]['contexts'][$context] = ($measurements[$d]['contexts'][$context] ?? 0) + 1;
                }
                if (count($measurements[$d]['examples']) < 3 && ! in_array($m->quote, $measurements[$d]['examples'], true)) {
                    $measurements[$d]['examples'][] = mb_substr($m->quote, 0, 120);
                }
            }

            // "key: value" lines, each key once a product.
            if (preg_match_all('/^\s*([^\n:]{2,30}?)\s*:\s*([^\n]{1,60})$/mu', $head, $lines, PREG_SET_ORDER) > 0) {
                $keys = [];
                foreach ($lines as [, $key, $value]) {
                    $key = trim($key);
                    if (isset($keys[$key]) || preg_match('/\d{3,}|https?/u', $key) === 1) {
                        continue;
                    }
                    $keys[$key] = true;
                    $keyValues[$key]['n'] = ($keyValues[$key]['n'] ?? 0) + 1;
                    $keyValues[$key]['values'][trim($value)] = ($keyValues[$key]['values'][trim($value)] ?? 0) + 1;
                }
            }

            // The title's words and pairs of words, each once a product.
            $tokens = array_values(array_filter(preg_split('/[^\p{L}\p{N}\'"״׳]+/u', mb_strtolower($title)) ?: [], fn (string $w): bool => mb_strlen($w) >= 2 && ! in_array($w, self::STOP, true) && preg_match('/^\d+$/u', $w) !== 1));
            foreach (array_unique($tokens) as $word) {
                $words[$word] = ($words[$word] ?? 0) + 1;
            }
            $titlePairs = [];
            for ($i = 0; $i < count($tokens) - 1; $i++) {
                $titlePairs[$tokens[$i].' '.$tokens[$i + 1]] = true;
            }
            foreach (array_keys($titlePairs) as $pair) {
                $pairs[$pair] = ($pairs[$pair] ?? 0) + 1;
            }

            foreach ($templates as $name => $template) {
                foreach ($template->productTypes() as $productType) {
                    if (self::matchesAny($title.' '.$type, (array) ($productType['patterns'] ?? []))) {
                        $fits[$name]++;

                        break;
                    }
                }
            }

            // The words a model used for the picture (colours, shapes, materials), each once a product.
            $picture = $pictures[$product->id] ?? null;
            if ($picture !== null) {
                $described++;
                foreach (array_unique(array_map(fn ($w): string => mb_strtolower(trim((string) $w)), $picture['words'])) as $word) {
                    if ($word !== '') {
                        $pictureWords[$word] = ($pictureWords[$word] ?? 0) + 1;
                    }
                }
            }

            if (count($examples) < self::EXAMPLES) {
                $examples[] = ['id' => (string) $product->external_id, 'title' => mb_substr($title, 0, 160), 'type' => $type, 'tags' => array_slice($tags, 0, 8), 'head' => mb_substr($head, 0, 240)]
                    + ($picture === null ? [] : ['picture' => mb_substr($picture['caption'], 0, 240)]);
            }
        }

        if ($n === 0) {
            return ['sampled' => 0];
        }
        $share = fn (int $count): float => round($count / $n, 3);

        return [
            'sampled' => $n,
            'fields' => [
                'product_type' => ['share' => $share($carriers['product_type']), 'values' => self::top($fields['product_type'], self::TOP_VALUES)],
                'tags' => ['share' => $share($carriers['tags']), 'values' => self::top($fields['tags'], self::TOP_TAGS)],
                'categories' => ['share' => $share($carriers['categories']), 'values' => self::top($fields['categories'], self::TOP_VALUES)],
            ],
            'attributes' => self::keyed($attributes, $share),
            'spec_fields' => self::keyed($specFields, $share),
            'measurements' => self::measurements($measurements, $share),
            'key_values' => self::keyed($keyValues, $share),
            'title_words' => self::frequent($words, $n, self::TOP_WORDS),
            'title_pairs' => self::frequent($pairs, $n, self::TOP_PAIRS),
            'image_words' => ['share' => $share($described), 'values' => self::top($pictureWords, self::TOP_TAGS)],
            'templates' => self::fits($fits, $share),
            'examples' => $examples,
        ];
    }

    /** The share of the products each pattern list matches, of a title (and the store's type) list. */
    public static function share(array $texts, array $patterns): float
    {
        if ($texts === [] || $patterns === []) {
            return 0.0;
        }

        return round(count(array_filter($texts, fn (string $text): bool => self::matchesAny($text, $patterns))) / count($texts), 3);
    }

    /** @param  list<string>  $patterns */
    public static function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match('~'.$pattern.'~iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The two words right before a measurement's first appearance, for its name ("משקל התכשיט"). */
    private static function before(string $text, string $raw): string
    {
        $at = mb_strpos($text, $raw);
        if ($at === false || $at === 0) {
            return '';
        }
        $words = preg_split('/[\s,;:()\-–]+/u', trim(mb_substr($text, max(0, $at - 40), min(40, $at)))) ?: [];
        $words = array_values(array_filter($words, fn (string $w): bool => $w !== '' && preg_match('/^\d/u', $w) !== 1));

        return implode(' ', array_slice($words, -2));
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int> the most common first, then by text
     */
    private static function top(array $counts, int $limit): array
    {
        uksort($counts, fn (int|string $a, int|string $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        return array_slice($counts, 0, $limit, true);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, float> share of the products, the most common first
     */
    private static function frequent(array $counts, int $n, int $limit): array
    {
        $counts = array_filter($counts, fn (int $c): bool => $c / $n >= self::MIN_SHARE && $c >= 2);

        return array_map(fn (int $c): float => round($c / $n, 3), self::top($counts, $limit));
    }

    /** @param  array<string, array{n: int, values?: array<string, int>}>  $keys */
    private static function keyed(array $keys, callable $share): array
    {
        $out = [];
        foreach (self::top(array_map(fn (array $k): int => $k['n'], $keys), self::TOP_KEYS) as $key => $n) {
            $out[$key] = ['share' => $share($n), 'values' => self::top($keys[$key]['values'] ?? [], self::TOP_VALUES)]
                + (isset($keys[$key]['for_variations']) ? ['for_variations' => $keys[$key]['for_variations']] : []);
        }

        return $out;
    }

    private static function measurements(array $measurements, callable $share): array
    {
        $out = [];
        foreach (self::top(array_map(fn (array $m): int => $m['n'], $measurements), self::TOP_KEYS) as $dimension => $n) {
            $m = $measurements[$dimension];
            $out[$dimension] = [
                'share' => $share($n),
                'min' => Measurement::compact($m['min']),
                'max' => Measurement::compact($m['max']),
                'units' => self::top($m['units'], 5),
                'before' => self::top($m['contexts'], self::TOP_CONTEXTS),
                'examples' => $m['examples'],
            ];
        }

        return $out;
    }

    /** @return array<string, float> */
    private static function fits(array $fits, callable $share): array
    {
        $out = array_map($share, array_filter($fits));
        uksort($out, fn (int|string $a, int|string $b): int => [$out[$b], (string) $a] <=> [$out[$a], (string) $b]);

        return $out;
    }
}
