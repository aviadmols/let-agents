<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Enums\ColorFamily;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * What a store's attribute bank does not cover yet, read in code from every product, never a sample (bank completion):
 *
 * - the products no product type reads, grouped as the store groups them (its own type, its category, else the last word
 *   of the title), each group with a few titles;
 * - by product type, the phrases its products say that no value of the bank reads: what the store itself says of a
 *   product (its attributes, metafields and variants' options), its tags, and what its picture's description says. A
 *   phrase of the store's own data counts though one product says it; another needs MIN_PRODUCTS of the type, so a rare
 *   attribute of a kind is found and a stray word is not.
 *
 * Nothing is read again from a picture: the descriptions kept are read. Colours, sizes and numbers are left out (the
 * colour filter and the sizes are read apart).
 */
final class BankGaps
{
    /** Products of a type that must say a phrase, unless the store's own data says it. */
    public const MIN_PRODUCTS = 2;

    /** Words a phrase holds at most: "thin straps", "high waisted", "spaghetti straps" are kept, a sentence is not. */
    private const MAX_WORDS = 4;

    /** Titles shown of a group of products no type reads. */
    private const TITLES = 6;

    /**
     * @param  iterable<CatalogProduct>  $products  every product of the store, with its variants
     * @param  array<string, array{caption: string, words: list<string>}>  $pictures  PictureText::for()
     * @param  int  $perType  phrases kept of each type, the most said first
     * @return array{untyped: list<array{group: string, products: int, titles: list<string>, store_type: string, categories: list<string>, ids: list<string>}>, phrases: array<string, list<array{phrase: string, products: int, store: bool}>>, read: array<string, array<string, mixed>>}
     */
    public static function find(VocabularyDefinition $vocabulary, iterable $products, array $pictures, BrandResolver $brands, int $perType = 40): array
    {
        $untyped = [];
        $said = [];
        $read = [];

        foreach ($products as $product) {
            $picture = isset($pictures[$product->id]) ? PictureText::text($pictures[$product->id]) : null;
            $reading = CodeReading::read($product, $brands, $vocabulary, readText: true, picture: $picture);
            $read[(string) $product->id] = $reading;
            $type = $reading['type']['key'] ?? null;

            if ($type === null) {
                $group = self::groupOf($product);
                $untyped[$group]['ids'][] = (string) $product->id;
                $untyped[$group]['titles'][] = (string) $product->title;
                $untyped[$group]['store_type'] = trim((string) ($product->payload['product_type'] ?? ''));
                $untyped[$group]['categories'] = array_values(array_unique([...($untyped[$group]['categories'] ?? []),
                    ...array_map(fn (array $path): string => (string) end($path), array_filter($product->categoryPaths()))]));
            }

            foreach (self::phrasesOf($product, $pictures[$product->id] ?? null) as $phrase => $fromStore) {
                if (self::covered($vocabulary, $phrase)) {
                    continue;
                }
                $at = $type ?? '';
                $said[$at][$phrase]['ids'][(string) $product->id] = true;
                $said[$at][$phrase]['store'] = ($said[$at][$phrase]['store'] ?? false) || $fromStore;
            }
        }

        $phrases = [];
        foreach ($said as $type => $byPhrase) {
            $kept = [];
            foreach ($byPhrase as $phrase => $seen) {
                $n = count($seen['ids']);
                if ($n >= self::MIN_PRODUCTS || $seen['store']) {
                    $kept[] = ['phrase' => (string) $phrase, 'products' => $n, 'store' => $seen['store']];
                }
            }
            usort($kept, fn (array $a, array $b): int => [$b['store'], $b['products'], $a['phrase']] <=> [$a['store'], $a['products'], $b['phrase']]);
            if ($kept !== []) {
                $phrases[(string) $type] = array_slice($kept, 0, $perType);
            }
        }

        $groups = [];
        foreach ($untyped as $group => $g) {
            $groups[] = ['group' => (string) $group, 'products' => count($g['ids']), 'titles' => array_slice(array_values(array_unique($g['titles'])), 0, self::TITLES),
                'store_type' => $g['store_type'], 'categories' => array_slice($g['categories'], 0, 4), 'ids' => $g['ids']];
        }
        usort($groups, fn (array $a, array $b): int => $b['products'] <=> $a['products']);

        return ['untyped' => $groups, 'phrases' => $phrases, 'read' => $read];
    }

    /** How the store groups a product: its own type, else its category, else the last word of its title. */
    private static function groupOf(CatalogProduct $product): string
    {
        $type = trim((string) ($product->payload['product_type'] ?? ''));
        if ($type !== '') {
            return mb_strtolower($type);
        }
        $paths = array_filter($product->categoryPaths());
        if ($paths !== []) {
            return mb_strtolower((string) end($paths[array_key_last($paths)]));
        }
        $words = preg_split('/\s+/u', trim((string) $product->title)) ?: [];

        return mb_strtolower((string) end($words));
    }

    /**
     * The phrases a product says of itself, each with whether the store's own data says it: its attributes' values (its
     * fields, metafields, the variants' options), its tags, and its picture's words.
     *
     * @param  array{caption: string, words: list<string>}|null  $picture
     * @return array<string, bool>
     */
    public static function phrasesOf(CatalogProduct $product, ?array $picture): array
    {
        $out = [];
        $add = function (string $text, bool $store) use (&$out): void {
            $phrase = self::phrase($text);
            if ($phrase !== null) {
                $out[$phrase] = ($out[$phrase] ?? false) || $store;
            }
        };
        foreach ($product->storeAttributes() as $attribute) {
            foreach ((array) $attribute['values'] as $value) {
                $add((string) $value, true);
            }
        }
        foreach ((array) ($product->payload['tags'] ?? []) as $tag) {
            $add((string) $tag, false);
        }
        foreach ((array) ($picture['words'] ?? []) as $word) {
            $add((string) $word, false);
        }

        return $out;
    }

    /** A phrase worth a value: a few words, letters in them, no number, no colour alone, no size. */
    private static function phrase(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(TextNormalizer::clean($text))), " \t\n\r\0\x0B,.;:-");
        if ($text === '' || mb_strlen($text) < 3 || mb_strlen($text) > 40 || preg_match('/\d/u', $text) === 1 || preg_match('/\p{L}/u', $text) !== 1) {
            return null;
        }
        if (count(explode(' ', $text)) > self::MAX_WORDS || ColorFamily::ofColourWord($text) !== null) {
            return null;
        }
        if (preg_match('/^(?:x{0,3}[sml]|x{1,3}l|one size|os)$/u', $text) === 1) {
            return null;
        }

        return $text;
    }

    /** Whether a value or a type of the bank already reads the phrase. */
    private static function covered(VocabularyDefinition $vocabulary, string $phrase): bool
    {
        foreach ($vocabulary->productTypes() as $type) {
            if (PatternMatch::first($phrase, (array) ($type['patterns'] ?? [])) !== null) {
                return true;
            }
        }
        foreach ($vocabulary->attributes() as $attribute) {
            foreach ((array) ($attribute['values'] ?? []) as $value) {
                if (PatternMatch::first($phrase, (array) ($value['patterns'] ?? [])) !== null) {
                    return true;
                }
            }
            if (($attribute['type'] ?? null) === 'boolean' && PatternMatch::first($phrase, (array) ($attribute['patterns'] ?? [])) !== null) {
                return true;
            }
        }

        return false;
    }
}
