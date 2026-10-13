<?php

namespace App\Modules\Enrichment\Scanning;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;

/**
 * Everything code can settle about a product without a model:
 *
 *   brand        from the brand field, a brand attribute, the title or the description
 *   family       the same product in other sizes (title without sizes, same category)
 *   type         the product type the store category stands for, when the vocabulary maps it
 *   category_choices  choice values the category stands for ("מוקצע לא מחוטא" is untreated)
 *   specs        sizes the vocabulary reads from the title ("20X45 מ"מ" is 20 thick, 45 wide)
 *   pack_count   "100 יח'" in the title
 *   choices      what a shopper must choose on the product page (a length, a volume)
 *   price_unit   the store's price note ("מחיר למטר")
 *   rental       a rental service, not a product to buy
 */
final class CodeReading
{
    public const VERSION = 1;

    private const RENTAL = '~^\s*השכרת|להשכרה~u';

    private const MAX_CHOICE_VALUES = 12;

    /**
     * How sure each source is: the store's own fields (its type, tags, attributes), then the
     * title, then the start of the description, then what a model saw in the picture.
     */
    public const CONFIDENCE = ['category' => 0.95, 'field' => 0.95, 'title' => 0.9, 'description' => 0.8, 'image' => 0.7];

    /** The start of the description read for choices, specs and flags: where stores state them. */
    private const HEAD = 600;

    /**
     * @param  bool  $readText  also read the vocabulary's choices, specs and flags from the store's
     *                          fields, the title and the start of the description (text_choices,
     *                          text_specs, flags), and the type from them when no category gives it
     * @param  string|null  $picture  what a model saw in its picture (PictureText): read last, for
     *                                choices and flags the vocabulary lets a picture tell
     * @return array<string, mixed>
     */
    public static function read(CatalogProduct $product, BrandResolver $brands, ?VocabularyDefinition $vocabulary, bool $readText = false, ?string $picture = null): array
    {
        $categories = ProductFamily::categoriesMostSpecificFirst($product);
        $type = $vocabulary?->typeForCategories($categories);
        $typeSource = 'category';

        // A category holds what the store put there: "זוית עץ אורן" sits with planed boards. When
        // the title names a different type, code does not decide; the model reads it.
        if ($type !== null && $vocabulary !== null) {
            $hints = ProductDigest::typeHints($product->title, $vocabulary);

            if ($hints !== [] && ! in_array($type, $hints, true)) {
                $type = null;
            }
        }

        $text = $readText && $vocabulary !== null ? self::texts($product) + ['image' => (string) $picture] : null;

        // No category gives it: the store's own type field, then the title, when exactly one type fits.
        if ($text !== null && $type === null && ! self::categoryNamesAnotherType($vocabulary, $categories)) {
            foreach (['field', 'title'] as $source) {
                $fits = array_values(array_filter($vocabulary->productTypes(), fn (array $t): bool => self::find($text[$source], (array) ($t['patterns'] ?? [])) !== null));
                if (count($fits) === 1) {
                    [$type, $typeSource] = [$fits[0]['key'], $source];
                    break;
                }
                if (count($fits) > 1) {
                    break;
                }
            }
        }

        $specs = $vocabulary?->titleSpecs($product->title, $type) ?: null;

        return array_filter([
            'version' => self::VERSION,
            'vocabulary' => $vocabulary?->key(),
            'brand' => $brands->resolve($product),
            'family' => ProductFamily::of($product),
            'type' => $type === null ? null : ['key' => $type, 'source' => $typeSource],
            'category_choices' => $vocabulary?->choicesForCategories($categories) ?: null,
            'specs' => $specs,
            'text_choices' => $text === null ? null : (self::textChoices($vocabulary, $type, $text, $vocabulary->choicesForCategories($categories)) ?: null),
            'text_specs' => $text === null ? null : (self::textSpecs($vocabulary, $type, $text, $specs ?? []) ?: null),
            'flags' => $text === null ? null : (self::flags($vocabulary, $type, $text) ?: null),
            'pack_count' => self::packCount($product->title),
            'choices' => self::choices($product) ?: null,
            'price_unit' => self::priceUnit($product),
            'rental' => preg_match(self::RENTAL, $product->title) === 1 ? true : null,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * The product's text by source, in the order code trusts them.
     *
     * @return array{field: string, title: string, description: string, names: list<string>, attributes: list<string>}
     */
    private static function texts(CatalogProduct $product): array
    {
        // The store's type, its categories' names, its tags and attributes: what the store itself says.
        // Names (type, categories, tags) group products; attributes describe this one (textChoices).
        $names = [trim((string) ($product->payload['product_type'] ?? ''))];
        foreach (array_filter($product->categoryPaths()) as $path) {
            $names[] = trim((string) end($path));
        }
        foreach ((array) ($product->payload['tags'] ?? []) as $tag) {
            $names[] = trim((string) $tag);
        }
        $attributes = [];
        foreach ($product->storeAttributes() as $attribute) {
            $attributes[] = $attribute['name'].': '.implode(', ', $attribute['values']);
        }
        $names = array_values(array_filter($names, fn (string $f): bool => $f !== ''));
        $fields = [...$names, ...$attributes];
        $description = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $product->description()));

        return [
            'field' => implode("\n", array_filter($fields, fn (string $f): bool => $f !== '')),
            'title' => TextNormalizer::clean($product->title),
            'description' => mb_substr(TextNormalizer::clean($description), 0, self::HEAD),
            'names' => $names,
            'attributes' => $attributes,
        ];
    }

    /**
     * Each choice the vocabulary has, by the first source that names a value of it. A single
     * choice named twice in one source ("זהב צהוב" and "זהב לבן" on a one-metal attribute) is
     * not decided here; a choice the category already gave is not read again.
     *
     * @param  array{field: string, title: string, description: string, image: string}  $text
     * @param  array<string, string>  $fromCategory
     * @return array<string, array{values: list<string>, source: string, quote: string}>
     */
    private static function textChoices(VocabularyDefinition $vocabulary, ?string $type, array $text, array $fromCategory): array
    {
        $out = [];

        foreach ($vocabulary->attributes() as $attribute) {
            $key = (string) $attribute['key'];
            if (($attribute['type'] ?? 'number') !== 'enum' || isset($fromCategory[$key]) || ! $vocabulary->appliesTo($key, $type)) {
                continue;
            }

            foreach (self::sources($vocabulary, $key) as $source) {
                $read = $source === 'field' ? self::withoutGroups($text, (array) ($attribute['values'] ?? [])) : $text[$source];
                $found = [];
                foreach ((array) ($attribute['values'] ?? []) as $value) {
                    $quote = self::find($read, (array) ($value['patterns'] ?? []));
                    if ($quote !== null) {
                        $found[(string) $value['key']] = $quote;
                    }
                }
                if ($found === []) {
                    continue;
                }
                if (count($found) > 1 && ! $vocabulary->isMultiple($key)) {
                    break;
                }
                $out[$key] = ['values' => array_keys($found), 'source' => $source, 'quote' => implode(' · ', array_unique(array_values($found)))];
                break;
            }
        }

        return $out;
    }

    /**
     * The store's own words for one attribute, without the names that group products: a category or
     * a tag that names more than one of its values ("ספיר, רובי, אמרלד", a collection) says nothing
     * about which one this product is. A store attribute that lists several (stones: ruby, diamond)
     * describes this product, and stays.
     *
     * @param  array{names?: list<string>, attributes?: list<string>, field: string}  $text
     * @param  list<array<string, mixed>>  $values
     */
    private static function withoutGroups(array $text, array $values): string
    {
        if (! isset($text['names'])) {
            return $text['field'];
        }

        $names = array_filter($text['names'], function (string $name) use ($values): bool {
            $named = 0;
            foreach ($values as $value) {
                $named += self::find($name, (array) ($value['patterns'] ?? [])) !== null ? 1 : 0;
            }

            return $named < 2;
        });

        return implode('
', [...$names, ...($text['attributes'] ?? [])]);
    }

    /**
     * The vocabulary's numbers the title did not give, read the same way from the start of the
     * description ("משקל התכשיט 1.1 גרם").
     *
     * @param  array{field: string, title: string, description: string, image: string}  $text
     * @param  array<string, mixed>  $fromTitle
     * @return array<string, array{value: float, unit: string, quote: string, source: string}>
     */
    private static function textSpecs(VocabularyDefinition $vocabulary, ?string $type, array $text, array $fromTitle): array
    {
        $out = [];
        foreach ($vocabulary->titleSpecs($text['description'], $type) as $key => $spec) {
            if (! isset($fromTitle[$key])) {
                $out[$key] = $spec + ['source' => 'description'];
            }
        }

        return $out;
    }

    /**
     * The vocabulary's yes-or-no attributes the text says outright.
     *
     * @param  array{field: string, title: string, description: string, image: string}  $text
     * @return array<string, array{source: string, quote: string}>
     */
    private static function flags(VocabularyDefinition $vocabulary, ?string $type, array $text): array
    {
        $out = [];
        foreach ($vocabulary->attributes() as $attribute) {
            $key = (string) $attribute['key'];
            if (($attribute['type'] ?? 'number') !== 'boolean' || ! $vocabulary->appliesTo($key, $type)) {
                continue;
            }
            foreach (self::sources($vocabulary, $key) as $source) {
                $quote = self::find($text[$source], (array) ($attribute['patterns'] ?? []));
                if ($quote !== null) {
                    $out[$key] = ['source' => $source, 'quote' => $quote];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Where an attribute is read, in order: the picture last, and only when the vocabulary lets a
     * picture tell it (not a purity, a weight, a number).
     *
     * @return list<string>
     */
    private static function sources(VocabularyDefinition $vocabulary, string $key): array
    {
        return $vocabulary->readFromImage($key) ? ['field', 'title', 'description', 'image'] : ['field', 'title', 'description'];
    }

    /** A category the vocabulary maps names a type the title does not: code leaves it to the model. */
    private static function categoryNamesAnotherType(VocabularyDefinition $vocabulary, array $categories): bool
    {
        return $categories !== [] && $vocabulary->typeForCategories($categories) !== null;
    }

    /**
     * The words around the first match of any pattern, or null.
     *
     * @param  list<string>  $patterns
     */
    private static function find(string $text, array $patterns): ?string
    {
        if ($text === '') {
            return null;
        }
        // A whole word only: "ספיר" inside "ספירלה" is no sapphire (PatternMatch).
        $match = PatternMatch::first($text, $patterns);

        return $match === null ? null : Snippet::around($text, $match[0], $match[1]);
    }

    private static function packCount(string $title): ?int
    {
        foreach ((new MeasurementScanner)->scan($title) as $measurement) {
            if ($measurement->dimension === 'count' && $measurement->value >= 2) {
                return (int) $measurement->value;
            }
        }

        return null;
    }

    /** @return list<array{name: string, values: list<string>}> */
    private static function choices(CatalogProduct $product): array
    {
        $choices = [];

        foreach ($product->storeAttributes() as $attribute) {
            if ($attribute['for_variations'] && count($attribute['values']) > 1) {
                $choices[] = ['name' => $attribute['name'], 'values' => array_slice($attribute['values'], 0, self::MAX_CHOICE_VALUES)];
            }
        }

        return $choices;
    }

    private static function priceUnit(CatalogProduct $product): ?string
    {
        $note = trim(strip_tags((string) ($product->payload['meta']['price_text'] ?? '')));

        return $note === '' ? null : mb_substr($note, 0, 40);
    }
}
