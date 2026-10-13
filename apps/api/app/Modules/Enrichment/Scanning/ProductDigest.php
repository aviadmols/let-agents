<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * Everything code can work out about a product before a model sees it, packed small:
 *
 *   text           the product text, condensed (no "click here", no repeats, within budget)
 *   measurements   every number with a unit, converted: ["m3", 13, "mm", "קוטר פוטר: 13 מ"מ"]
 *   candidates     choices and tags whose patterns matched: ["c2", "kit", "body_only", "גוף בלבד"]
 *   type_hints     product types whose patterns matched the title
 *   known          what code already settled: brand, a type the category stands for, title sizes
 *
 * The model answers with IDs from these lists, so it never retypes a value or a quote, and
 * every answer can be checked against what code found.
 */
final class ProductDigest
{
    /**
     * @param  array<string, mixed>  $request  what the model receives
     * @param  array<string, mixed>  $context  what the importer needs to check the answer
     */
    private function __construct(
        public readonly array $request,
        public readonly array $context,
        public readonly string $inputHash,
    ) {}

    /**
     * @param  list<string>  $boilerplate  normalized lines repeated across the catalog, dropped
     * @param  array<string, mixed>  $known  facts code already wrote (see ReadProductsInCode)
     */
    public static function build(CatalogProduct $product, VocabularyDefinition $vocabulary, int $maxTextChars, string $promptHash, array $boilerplate = [], array $known = []): self
    {
        $condensed = (new TextCondenser)->condense(self::sections($product), $maxTextChars, $boilerplate);
        $text = $condensed['text'];

        $measurements = (new MeasurementScanner)->scan($text);
        $typeHints = isset($known['type']) ? [] : self::typeHints($product->title, $vocabulary);
        $candidates = self::candidates($text, $vocabulary, $known['type'] ?? (count($typeHints) === 1 ? $typeHints[0] : null));

        $request = array_filter([
            'id' => $product->external_id,
            'title' => $product->title,
            'brand' => $product->brand,
            'category' => self::category($product, $vocabulary),
            'text' => $text,
            'measurements' => array_map(fn (Measurement $m): array => array_values($m->forAgent()), $measurements),
            'candidates' => array_map(fn (array $c): array => [$c['id'], $c['key'], $c['value'], $c['quote']], $candidates),
            'type_hints' => $typeHints,
            'known' => $known,
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);

        $context = [
            'product_id' => $product->id,
            'text' => $text,
            'truncated' => $condensed['truncated'],
            'measurements' => array_map(fn (Measurement $m): array => $m->toArray(), $measurements),
            'candidates' => $candidates,
            'type_hints' => $typeHints,
            'vocabulary_hash' => $vocabulary->hash(),
            // Attributes the shopper chooses on the product page ("קוטר: 3.5 מ"מ, 4.2 מ"מ"): a size
            // among them is an option, not this product's spec.
            'choice_names' => array_values(array_map(
                fn (array $a): string => TextNormalizer::forMatching($a['name']),
                array_filter($product->storeAttributes(), fn (array $a): bool => $a['for_variations'] && count($a['values']) > 1),
            )),
        ];

        $inputHash = hash('sha256', $promptHash.'|'.$vocabulary->hash().'|'.json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return new self($request, $context, $inputHash);
    }

    /** @return list<string> the product's text, title first */
    public static function sections(CatalogProduct $product): array
    {
        return [
            $product->title,
            $product->shortDescription(),
            $product->description(),
            implode("\n", array_map(fn (array $a): string => $a['name'].': '.implode(', ', $a['values']), $product->storeAttributes())),
            implode("\n", $product->specFields()),
        ];
    }

    /**
     * Choice values, yes/no specs and tags whose patterns appear in the text.
     *
     * @return list<array{id: string, kind: string, key: string, value: string|bool, quote: string}>
     */
    public static function candidates(string $text, VocabularyDefinition $vocabulary, ?string $type = null): array
    {
        $found = [];

        foreach ($vocabulary->attributes() as $attribute) {
            $attributeType = $attribute['type'] ?? 'number';

            // "קודח / לא קודח" is two variants to choose from, not a yes: exclude_patterns rule a candidate out.
            if (self::firstMatch($text, (array) ($attribute['exclude_patterns'] ?? [])) !== null) {
                continue;
            }

            if ($attributeType === 'enum') {
                foreach ((array) $attribute['values'] as $value) {
                    if (($quote = self::firstMatch($text, (array) ($value['patterns'] ?? []))) !== null) {
                        $found[] = ['kind' => 'enum', 'key' => $attribute['key'], 'value' => $value['key'], 'quote' => $quote];
                    }
                }
            }

            if ($attributeType === 'boolean' && ($quote = self::firstMatch($text, (array) ($attribute['patterns'] ?? []))) !== null) {
                $found[] = ['kind' => 'boolean', 'key' => $attribute['key'], 'value' => true, 'quote' => $quote];
            }
        }

        foreach ($vocabulary->tags() as $tag) {
            if (($quote = self::firstMatch($text, (array) ($tag['patterns'] ?? []))) !== null) {
                $found[] = ['kind' => 'tag', 'key' => 'tag', 'value' => $tag['key'], 'quote' => $quote];
            }
        }

        // Jobs named in the text ("לבניית פרגולות"), then jobs usually done with this type of
        // product, offered with an empty quote so the model accepts or rejects each one.
        foreach ($vocabulary->uses() as $use) {
            if (($quote = self::firstMatch($text, (array) ($use['patterns'] ?? []))) !== null) {
                $found[] = ['kind' => 'use', 'key' => 'use', 'value' => $use['key'], 'quote' => $quote];
            } elseif ($type !== null && in_array($type, (array) ($use['types'] ?? []), true)) {
                $found[] = ['kind' => 'use', 'key' => 'use', 'value' => $use['key'], 'quote' => ''];
            }
        }

        return array_values(array_map(fn (array $c, int $i): array => ['id' => 'c'.($i + 1)] + $c, $found, array_keys($found)));
    }

    /** @return list<string> */
    public static function typeHints(string $title, VocabularyDefinition $vocabulary): array
    {
        $title = TextNormalizer::clean($title);
        $hints = [];

        foreach ($vocabulary->productTypes() as $type) {
            if (self::firstMatch($title, (array) ($type['patterns'] ?? [])) !== null) {
                $hints[] = $type['key'];
            }
        }

        return $hints;
    }

    /** @param list<string> $patterns */
    private static function firstMatch(string $text, array $patterns): ?string
    {
        $best = null;

        foreach ($patterns as $pattern) {
            // A whole word only: "ספיר" inside "ספירלה" is no sapphire (PatternMatch).
            $match = PatternMatch::first($text, [$pattern]);
            if ($match !== null && ($best === null || $match[0] < $best[1])) {
                $best = [substr($text, $match[0], $match[1]), $match[0]];
            }
        }

        return $best === null ? null : Snippet::around($text, $best[1], strlen($best[0]));
    }

    /** The product's category paths inside the vocabulary's branch, most specific first. */
    private static function category(CatalogProduct $product, VocabularyDefinition $vocabulary): ?string
    {
        $paths = $product->categoryPaths();
        usort($paths, fn (array $a, array $b): int => count($b) <=> count($a));

        foreach ($product->payload['categories'] ?? [] as $category) {
            if (in_array((string) ($category['id'] ?? ''), $vocabulary->excludedCategoryExternalIds(), true)) {
                return null;
            }
        }

        return $paths === [] ? null : implode(' › ', $paths[0]);
    }
}
