<?php

namespace App\Modules\Enrichment\Vocabulary;

use App\Modules\Enrichment\Scanning\TryOnEligibility;
use App\Modules\Enrichment\Scanning\Units;

/**
 * What the agents may say about one branch of a store's catalog: the product types, the specs
 * with their units and sane ranges, the fixed choices (corded or cordless), and the tags.
 * Everything a model returns is checked against this. It is data, edited per store, not code.
 *
 * Patterns are regular expressions matched in code first. A model then confirms or rejects
 * what code found, instead of reading everything from scratch.
 */
final class VocabularyDefinition
{
    public const SCHEMA_VERSION = 1;

    public const RANK_DIRECTIONS = ['min', 'max'];

    /** How much a shopper needs an attribute known before buying. docs/strict-scan.md */
    public const REQUIRED = 'required';

    public const RECOMMENDED = 'recommended';

    public const OPTIONAL = 'optional';

    public const IMPORTANCE = [self::REQUIRED, self::RECOMMENDED, self::OPTIONAL];

    /**
     * `read_by` on a choice that says what kind of product it is (a book's genre, a jewel's style, a tool's
     * job): stores seldom write the kind in a field or in so many words, so a model reads it from the whole
     * description, and no pattern needs to match for it or its values to stay.
     */
    public const READ_BY_MODEL = 'model';

    /** A root of "*" is the whole catalog: a store that sells one kind of thing has no one category above it. */
    public const WHOLE_CATALOG = '*';

    /**
     * How sure a reading of a product's picture must be before its value filters results, when
     * the vocabulary does not say. Below it the value only helps the order.
     */
    public const DEFAULT_IMAGE_FILTER_CONFIDENCE = 0.8;

    /**
     * A colour, as the picture's description says it ("שתי אבנים בצבע אדום"), filters at once: a colour is
     * what a picture tells best, and a store's titles rarely say it ("רובי", never "אדום").
     */
    public const COLOUR_IMAGE_FILTER_CONFIDENCE = 0.7;

    /** The key `image.filter_confidence` uses for the product type, beside attribute keys and `default`. */
    public const PRODUCT_TYPE = 'product_type';

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private readonly array $data) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?self, 1: list<string>} the definition, or null and the problems found
     */
    public static function parse(array $data): array
    {
        $problems = [];

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $problems[] = 'schema_version must be '.self::SCHEMA_VERSION;
        }

        foreach (['key', 'root_category_external_id'] as $required) {
            if (! is_string($data[$required] ?? null) || trim($data[$required]) === '') {
                $problems[] = "{$required} is required";
            }
        }

        if (! is_string($data['name']['he'] ?? null) || ! is_string($data['name']['en'] ?? null)) {
            $problems[] = 'name needs he and en';
        }

        $keys = [];
        foreach ((array) ($data['product_types'] ?? []) as $i => $type) {
            $problems = [...$problems, ...self::checkEntry("product_types[{$i}]", $type, $keys)];
        }

        if (($data['product_types'] ?? []) === []) {
            $problems[] = 'product_types must not be empty';
        }

        foreach ((array) ($data['attributes'] ?? []) as $i => $attribute) {
            $at = "attributes[{$i}]";
            $problems = [...$problems, ...self::checkEntry($at, $attribute, $keys, patternsRequired: false)];
            $type = $attribute['type'] ?? 'number';

            if (! in_array($type, ['number', 'enum', 'boolean'], true)) {
                $problems[] = "{$at}.type must be number, enum or boolean";
            }

            if ($type === 'number') {
                if (! array_key_exists((string) ($attribute['dimension'] ?? ''), Units::CANONICAL)) {
                    $problems[] = "{$at}.dimension must be one of: ".implode(', ', array_keys(Units::CANONICAL));
                }
                if (! is_numeric($attribute['min'] ?? null) || ! is_numeric($attribute['max'] ?? null) || (float) $attribute['min'] > (float) $attribute['max']) {
                    $problems[] = "{$at} needs numeric min <= max";
                }
                if (isset($attribute['title_capture'])) {
                    $capture = $attribute['title_capture'];
                    $valid = is_array($capture)
                        && is_string($capture['pattern'] ?? null)
                        && @preg_match('~'.$capture['pattern'].'~iu', '') !== false
                        && is_int($capture['group'] ?? null) && $capture['group'] >= 1
                        && (! isset($capture['factor']) || (is_numeric($capture['factor']) && $capture['factor'] > 0));

                    if (! $valid) {
                        $problems[] = "{$at}.title_capture needs a valid pattern, a group number and an optional positive factor";
                    }
                }
            }

            if (isset($attribute['multiple']) && ($type !== 'enum' || ! is_bool($attribute['multiple']))) {
                $problems[] = "{$at}.multiple is true or false, on a choice only";
            }

            if (isset($attribute['read_by']) && ($type !== 'enum' || $attribute['read_by'] !== self::READ_BY_MODEL)) {
                $problems[] = "{$at}.read_by is \"".self::READ_BY_MODEL.'", on a choice only';
            }

            if ($type === 'enum') {
                $valueKeys = [];
                foreach ((array) ($attribute['values'] ?? []) as $j => $value) {
                    $problems = [...$problems, ...self::checkEntry("{$at}.values[{$j}]", $value, $valueKeys)];

                    foreach ((array) ($value['categories'] ?? []) as $category) {
                        if (! is_string($category) || trim($category) === '') {
                            $problems[] = "{$at}.values[{$j}].categories must list category external ids";
                        }
                    }
                }
                if (count($valueKeys) < 2) {
                    $problems[] = "{$at}.values needs at least two values";
                }
            }

            if ($type === 'boolean') {
                $problems = [...$problems, ...self::checkPatterns("{$at}.patterns", $attribute['patterns'] ?? null, required: true)];
            }

            $problems = [...$problems, ...self::checkPatterns("{$at}.exclude_patterns", $attribute['exclude_patterns'] ?? null, required: false)];

            if (isset($attribute['rank']) && ! in_array($attribute['rank'], self::RANK_DIRECTIONS, true)) {
                $problems[] = "{$at}.rank must be min, max or absent";
            }
        }

        $tagKeys = [];
        foreach ((array) ($data['tags'] ?? []) as $i => $tag) {
            $problems = [...$problems, ...self::checkEntry("tags[{$i}]", $tag, $tagKeys)];
        }

        $typeKeys = array_map(fn ($t): string => (string) ($t['key'] ?? ''), (array) ($data['product_types'] ?? []));

        foreach ((array) ($data['product_types'] ?? []) as $i => $type) {
            foreach ((array) ($type['categories'] ?? []) as $j => $category) {
                if (! is_string($category) || trim($category) === '') {
                    $problems[] = "product_types[{$i}].categories[{$j}] must be a category external id";
                }
            }
        }

        $useKeys = [];
        foreach ((array) ($data['uses'] ?? []) as $i => $use) {
            $problems = [...$problems, ...self::checkEntry("uses[{$i}]", $use, $useKeys)];

            foreach ((array) ($use['types'] ?? []) as $type) {
                if (! in_array($type, $typeKeys, true)) {
                    $problems[] = "uses[{$i}].types: unknown product type {$type}";
                }
            }
        }

        $attributeKeys = array_map(fn ($a): string => (string) ($a['key'] ?? ''), (array) ($data['attributes'] ?? []));
        foreach (['set_by', 'price_set_by'] as $facet) {
            foreach ((array) ($data['comparison'][$facet] ?? []) as $key) {
                if (! in_array($key, $attributeKeys, true)) {
                    $problems[] = "comparison.{$facet}: unknown attribute {$key}";
                }
            }
        }

        $componentKeys = [];
        foreach ((array) ($data['components'] ?? []) as $i => $component) {
            $problems = [...$problems, ...self::checkEntry("components[{$i}]", $component, $componentKeys, patternsRequired: true)];

            foreach ((array) ($component['attributes'] ?? []) as $key) {
                if (! in_array($key, $attributeKeys, true)) {
                    $problems[] = "components[{$i}].attributes: unknown attribute {$key}";
                }
            }
        }

        $problems = [...$problems, ...self::checkImage((array) ($data['image'] ?? []), $attributeKeys)];
        $problems = [...$problems, ...self::checkTryOn($data), ...self::checkImportance((array) ($data['attributes'] ?? []))];

        return $problems === [] ? [new self($data), []] : [null, $problems];
    }

    /** @param array<string, mixed> $data */
    public static function fromTrusted(array $data): self
    {
        return new self($data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function key(): string
    {
        return (string) $this->data['key'];
    }

    public function name(string $locale): string
    {
        return (string) ($this->data['name'][$locale] ?? $this->data['name']['en'] ?? $this->key());
    }

    public function rootCategoryExternalId(): string
    {
        return (string) $this->data['root_category_external_id'];
    }

    public function coversWholeCatalog(): bool
    {
        return $this->rootCategoryExternalId() === self::WHOLE_CATALOG;
    }

    /** Whether a choice holds several values at once: a ring set with diamonds and a ruby has both stones. */
    public function isMultiple(string $attributeKey): bool
    {
        return ($this->attribute($attributeKey)['multiple'] ?? false) === true;
    }

    /**
     * The parts a product is described by, each with the words that name its place ("מרכזי",
     * "מוקפת") and the attributes that describe it, so "אבן אדומה באמצע" is a red center stone.
     *
     * @return list<array{key: string, label: array<string, string>, patterns: list<string>, attributes?: list<string>, hint?: string}>
     */
    public function components(): array
    {
        return array_values((array) ($this->data['components'] ?? []));
    }

    /**
     * How sure a picture's reading of this attribute (or `product_type`) must be before its value
     * filters results. Each attribute has its own risk: a colour seen wrong costs less than a stone.
     */
    public function imageFilterConfidence(string $key): float
    {
        $thresholds = (array) ($this->data['image']['filter_confidence'] ?? []);

        if (! isset($thresholds[$key]) && preg_match('/(?:^|_)colou?rs?(?:$|_)/', $key) === 1) {
            return self::COLOUR_IMAGE_FILTER_CONFIDENCE;
        }

        return (float) ($thresholds[$key] ?? $thresholds['default'] ?? self::DEFAULT_IMAGE_FILTER_CONFIDENCE);
    }

    /**
     * Words in an attribute's key that say it is about how the product looks: what a picture can
     * show (colour, shape, pattern, material, stone, setting, the cut of a garment).
     */
    public const LOOKS = '/(?:^|_)(?:colou?rs?|shapes?|patterns?|prints?|materials?|fabrics?|finish|texture|metals?|stones?|styles?|motifs?|setting|cut|neckline|sleeves?|collar|fit|silhouette|closure|heel|toe|design|look)(?:$|_)/';

    /**
     * Whether a picture may say this at all. Only how the product looks: a choice of colour,
     * shape, pattern, material, stone or cut. Never a number (a karat, a weight, a size, a
     * capacity) and never a yes/no (a battery included, a charger in the box): a picture shows the
     * piece, not what comes with it. The vocabulary names what a picture may tell under
     * `image.filter_confidence` or with `visible` on the attribute; otherwise its key must say
     * it is about looks. `image.never` always wins.
     */
    public function readFromImage(string $key): bool
    {
        if (in_array($key, (array) ($this->data['image']['never'] ?? []), true)) {
            return false;
        }

        if ($key === self::PRODUCT_TYPE) {
            return true;
        }

        $attribute = $this->attribute($key);

        if ($attribute === null || in_array($attribute['type'] ?? 'number', ['number', 'boolean'], true)) {
            return false;
        }

        if (array_key_exists('visible', $attribute)) {
            return $attribute['visible'] === true;
        }

        return array_key_exists($key, (array) ($this->data['image']['filter_confidence'] ?? []))
            || preg_match(self::LOOKS, $key) === 1;
    }

    /** @return list<string> */
    public function excludedCategoryExternalIds(): array
    {
        return array_values(array_map('strval', (array) ($this->data['excluded_category_external_ids'] ?? [])));
    }

    /** @return list<array{key: string, label: array<string, string>, patterns?: list<string>, hint?: string}> */
    public function productTypes(): array
    {
        return array_values((array) $this->data['product_types']);
    }

    /** @return list<array<string, mixed>> */
    public function attributes(): array
    {
        return array_values((array) ($this->data['attributes'] ?? []));
    }

    /** @return array<string, mixed>|null */
    public function attribute(string $key): ?array
    {
        foreach ($this->attributes() as $attribute) {
            if ($attribute['key'] === $key) {
                return $attribute + ['type' => 'number'];
            }
        }

        return null;
    }

    /** Whether a choice is the kind of product, read by a model from the whole description (READ_BY_MODEL). */
    public function readByModel(string $attributeKey): bool
    {
        return ($this->attribute($attributeKey)['read_by'] ?? null) === self::READ_BY_MODEL;
    }

    /** Whether any choice is the kind of product (READ_BY_MODEL). */
    public function hasKind(): bool
    {
        foreach ($this->attributes() as $attribute) {
            if (($attribute['type'] ?? 'number') === 'enum' && ($attribute['read_by'] ?? null) === self::READ_BY_MODEL) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{key: string, label: array<string, string>, patterns?: list<string>, hint?: string}> */
    public function tags(): array
    {
        return array_values((array) ($this->data['tags'] ?? []));
    }

    /**
     * Jobs and projects a product can be good for ("deck", "pergola"). `types` lists the product
     * types usually used for the job, which code offers to the model as candidates to confirm.
     *
     * @return list<array{key: string, label: array<string, string>, patterns?: list<string>, types?: list<string>, hint?: string}>
     */
    public function uses(): array
    {
        return array_values((array) ($this->data['uses'] ?? []));
    }

    public function hasUse(string $key): bool
    {
        return in_array($key, array_column($this->uses(), 'key'), true);
    }

    /**
     * The product type a store category stands for, when the vocabulary maps categories to types.
     * Categories are tried most specific first; the first one that maps to exactly one type wins.
     *
     * @param  list<string>  $categoryExternalIds  most specific first
     */
    public function typeForCategories(array $categoryExternalIds): ?string
    {
        return $this->valueForCategories($this->productTypes(), $categoryExternalIds);
    }

    /**
     * Choice values a store category stands for: "מוקצע לא מחוטא" is untreated wood even when the
     * title does not say so.
     *
     * @param  list<string>  $categoryExternalIds  most specific first
     * @return array<string, string> attribute key => value key
     */
    public function choicesForCategories(array $categoryExternalIds): array
    {
        $choices = [];

        foreach ($this->attributes() as $attribute) {
            if (($attribute['type'] ?? 'number') !== 'enum') {
                continue;
            }

            $value = $this->valueForCategories((array) $attribute['values'], $categoryExternalIds);

            if ($value !== null) {
                $choices[$attribute['key']] = $value;
            }
        }

        return $choices;
    }

    /**
     * @param  list<array<string, mixed>>  $entries  entries with optional `categories`
     * @param  list<string>  $categoryExternalIds  most specific first
     */
    private function valueForCategories(array $entries, array $categoryExternalIds): ?string
    {
        foreach ($categoryExternalIds as $categoryId) {
            $keys = [];

            foreach ($entries as $entry) {
                if (in_array((string) $categoryId, array_map('strval', (array) ($entry['categories'] ?? [])), true)) {
                    $keys[] = $entry['key'];
                }
            }

            if (count($keys) === 1) {
                return $keys[0];
            }
        }

        return null;
    }

    /**
     * Number specs code reads from the title with a pattern, such as the 20 and 45 of
     * "עץ אורן מוקצע בפרופיל 20X45 מ"מ".
     *
     * @return array<string, array{value: float, unit: string, quote: string}>
     */
    public function titleSpecs(string $title, ?string $type): array
    {
        $specs = [];
        // Where in the words each measure's number was read.
        $at = [];

        foreach ($this->attributes() as $attribute) {
            $capture = $attribute['title_capture'] ?? null;

            if (($attribute['type'] ?? 'number') !== 'number' || ! is_array($capture) || ! $this->appliesTo($attribute['key'], $type)) {
                continue;
            }

            if (@preg_match('~'.$capture['pattern'].'~iu', $title, $match, PREG_OFFSET_CAPTURE) !== 1 || ! isset($match[$capture['group']]) || $match[$capture['group']][1] < 0) {
                continue;
            }

            $number = (float) str_replace(',', '.', $match[$capture['group']][0]);
            $value = $number * (float) ($capture['factor'] ?? 1);

            if ($value < (float) $attribute['min'] || $value > (float) $attribute['max']) {
                continue;
            }

            $specs[$attribute['key']] = [
                'value' => round($value, 6),
                'unit' => Units::CANONICAL[$attribute['dimension']],
                'quote' => $match[0][0],
            ];
            $at[$match[$capture['group']][1]][] = $attribute['key'];
        }

        // One number read as several measures ("3*3.60" as a length, a width and a height of 3 cm
        // each) is none of them: the words do not say which it is. Two numbers alike in two places
        // ("45×45") are two measures, and stay.
        foreach ($at as $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    unset($specs[$key]);
                }
            }
        }

        return $specs;
    }

    public function hasProductType(string $key): bool
    {
        return in_array($key, array_column($this->productTypes(), 'key'), true);
    }

    public function hasTag(string $key): bool
    {
        return in_array($key, array_column($this->tags(), 'key'), true);
    }

    /**
     * Whether the vocabulary says anything about try-on: `"tryon": false` on the whole branch, or a
     * `tryon` class on at least one product type. When it does not, no product is marked either way.
     */
    public function declaresTryOn(): bool
    {
        if (($this->data['tryon'] ?? null) === false) {
            return true;
        }

        foreach ($this->productTypes() as $type) {
            if (isset($type['tryon'])) {
                return true;
            }
        }

        return false;
    }

    /** The try-on class a product type stands for (see TryOnEligibility::CLASSES), or null when it is not one. */
    public function tryOnClass(string $typeKey): ?string
    {
        if (($this->data['tryon'] ?? null) === false) {
            return null;
        }

        foreach ($this->productTypes() as $type) {
            if ($type['key'] === $typeKey) {
                return isset($type['tryon']) ? (string) $type['tryon'] : null;
            }
        }

        return null;
    }

    /** @return list<string> words of a part or an accessory that is not the piece itself (a leg for a table) */
    public function tryOnExcludePatterns(): array
    {
        return array_values(array_map('strval', (array) ($this->data['tryon_exclude_patterns'] ?? [])));
    }

    /**
     * How much a shopper needs an attribute known before buying: `required`, `recommended` or
     * `optional`. A vocabulary that does not say is optional, so older vocabularies ask nothing new.
     */
    public function importance(string $attributeKey): string
    {
        $importance = (string) ($this->attribute($attributeKey)['importance'] ?? self::OPTIONAL);

        return in_array($importance, self::IMPORTANCE, true) ? $importance : self::OPTIONAL;
    }

    /** @return list<array<string, mixed>> the attributes of one importance, in the vocabulary's own order */
    public function attributesOf(string $importance): array
    {
        return array_values(array_filter($this->attributes(), fn (array $a): bool => $this->importance((string) $a['key']) === $importance));
    }

    /** Whether an attribute applies to a product type; no list means every type. */
    public function appliesTo(string $attributeKey, ?string $productType): bool
    {
        $applies = (array) ($this->attribute($attributeKey)['applies_to'] ?? []);

        return $applies === [] || $productType === null || in_array($productType, $applies, true);
    }

    /** @return list<string> attributes whose values split products into comparable sets */
    public function setBy(): array
    {
        return array_values((array) ($this->data['comparison']['set_by'] ?? []));
    }

    /** @return list<string> extra split for price only: a tool with batteries is not cheaper than its body alone */
    public function priceSetBy(): array
    {
        return array_values((array) ($this->data['comparison']['price_set_by'] ?? []));
    }

    public function label(string $kind, string $key, string $locale, ?string $valueKey = null): string
    {
        $entries = match ($kind) {
            'type' => $this->productTypes(),
            'tag' => $this->tags(),
            'use' => $this->uses(),
            default => $this->attributes(),
        };

        foreach ($entries as $entry) {
            if ($entry['key'] !== $key) {
                continue;
            }

            if ($valueKey !== null) {
                foreach ((array) ($entry['values'] ?? []) as $value) {
                    if ($value['key'] === $valueKey) {
                        return (string) ($value['label'][$locale] ?? $valueKey);
                    }
                }

                return $valueKey;
            }

            return (string) ($entry['label'][$locale] ?? $key);
        }

        return $valueKey ?? $key;
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, true>  $keys  keys already used in the same list
     * @return list<string>
     */
    private static function checkEntry(string $at, mixed $entry, array &$keys, bool $patternsRequired = false): array
    {
        if (! is_array($entry)) {
            return ["{$at} must be an object"];
        }

        $problems = [];
        $key = $entry['key'] ?? null;

        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key)) {
            $problems[] = "{$at}.key must be snake_case";
        } elseif (isset($keys[$key])) {
            $problems[] = "{$at}.key {$key} is used twice";
        } else {
            $keys[$key] = true;
        }

        if (! is_string($entry['label']['he'] ?? null) || ! is_string($entry['label']['en'] ?? null)) {
            $problems[] = "{$at}.label needs he and en";
        }

        return [...$problems, ...self::checkPatterns("{$at}.patterns", $entry['patterns'] ?? null, $patternsRequired)];
    }

    /**
     * Try-on: a class per product type from TryOnEligibility::CLASSES, `"tryon": false` for a branch
     * with none, and patterns for the words of a part or an accessory.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function checkTryOn(array $data): array
    {
        $problems = [];

        if (isset($data['tryon']) && $data['tryon'] !== false) {
            $problems[] = 'tryon is false or absent on the vocabulary; a class goes on a product type';
        }

        foreach ((array) ($data['product_types'] ?? []) as $i => $type) {
            if (is_array($type) && isset($type['tryon']) && ! array_key_exists((string) $type['tryon'], TryOnEligibility::CLASSES)) {
                $problems[] = "product_types[{$i}].tryon must be one of: ".implode(', ', array_keys(TryOnEligibility::CLASSES));
            }
        }

        return [...$problems, ...self::checkPatterns('tryon_exclude_patterns', $data['tryon_exclude_patterns'] ?? null, required: false)];
    }

    /**
     * How much a shopper needs each attribute known: required, recommended or optional, and a short
     * reason (`why`) in both languages for the person who reads the vocabulary.
     *
     * @param  list<mixed>  $attributes
     * @return list<string>
     */
    private static function checkImportance(array $attributes): array
    {
        $problems = [];

        foreach ($attributes as $i => $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            if (isset($attribute['importance']) && ! in_array($attribute['importance'], self::IMPORTANCE, true)) {
                $problems[] = "attributes[{$i}].importance must be one of: ".implode(', ', self::IMPORTANCE);
            }

            if (isset($attribute['why']) && (! is_string($attribute['why']['he'] ?? null) || ! is_string($attribute['why']['en'] ?? null))) {
                $problems[] = "attributes[{$i}].why needs he and en";
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $image
     * @param  list<string>  $attributeKeys
     * @return list<string>
     */
    private static function checkImage(array $image, array $attributeKeys): array
    {
        $problems = [];
        $known = [...$attributeKeys, self::PRODUCT_TYPE];

        foreach ((array) ($image['filter_confidence'] ?? []) as $key => $threshold) {
            if ($key !== 'default' && ! in_array($key, $known, true)) {
                $problems[] = "image.filter_confidence: unknown attribute {$key}";
            }
            if (! is_numeric($threshold) || (float) $threshold < 0 || (float) $threshold > 1) {
                $problems[] = "image.filter_confidence.{$key} must be between 0 and 1";
            }
        }

        foreach ((array) ($image['never'] ?? []) as $key) {
            if (! in_array($key, $known, true)) {
                $problems[] = "image.never: unknown attribute {$key}";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function checkPatterns(string $at, mixed $patterns, bool $required): array
    {
        if ($patterns === null) {
            return $required ? ["{$at} is required"] : [];
        }

        if (! is_array($patterns)) {
            return ["{$at} must be a list"];
        }

        $problems = [];
        foreach ($patterns as $i => $pattern) {
            if (! is_string($pattern) || $pattern === '' || @preg_match('~'.$pattern.'~iu', '') === false) {
                $problems[] = "{$at}[{$i}] is not a valid pattern";
            }
        }

        return $problems;
    }
}
