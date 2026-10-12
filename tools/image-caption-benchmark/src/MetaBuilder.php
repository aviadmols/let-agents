<?php

namespace ImageCaptionBenchmark;

use App\Modules\Search\Support\HebrewSearch;

/**
 * One product's meta object. The catalogue is the source of truth; what a model saw in the picture
 * only fills what the catalogue left empty, and every field says where it came from.
 *
 * Sources: catalog (a field of the product), variant (a field of a variant), catalog_text (read by
 * code from the title, description or tags), image (a model's reading of the picture), generated
 * (normalization and the search texts built here).
 */
final class MetaBuilder
{
    public const PROMPT_VERSION = 'benchmark-v1';

    /** What a picture cannot tell: a model that answers these has invented them. */
    public const NOT_FROM_PICTURE = ['price', 'size', 'sizes', 'weight', 'dimensions', 'brand', 'sku', 'karat', 'model_number', 'barcode'];

    private const IMAGE_LISTS = ['visible_colors' => 'color', 'visible_materials' => 'material', 'visible_stones' => 'stone', 'visible_shapes' => 'shape', 'visible_patterns' => 'pattern', 'visible_styles' => 'style'];

    private const SIZE_OPTION = '/^(size|מידה|מידות|גודל)$/iu';

    private const COLOR_OPTION = '/^(color|colour|צבע)$/iu';

    private const MATERIAL_OPTION = '/^(material|חומר|מתכת|metal)$/iu';

    /** @param list<string> $collections */
    public static function fromCatalog(array $product, array $collections, string $currency): array
    {
        $sources = [];
        $set = function (string $path, string $source) use (&$sources): void {
            $sources[$path] = $source;
        };

        $title = trim((string) $product['title']);
        $description = self::plain((string) ($product['body_html'] ?? ''));
        $tags = array_values(array_filter(array_map('trim', is_array($product['tags']) ? $product['tags'] : explode(',', (string) $product['tags']))));
        $type = trim((string) ($product['product_type'] ?? ''));
        $variants = (array) ($product['variants'] ?? []);
        $optionNames = array_map(fn (array $o): string => trim((string) $o['name']), (array) ($product['options'] ?? []));
        $text = implode(' ', [$title, $type, implode(' ', $tags), $description]);

        // identity
        $skus = array_values(array_unique(array_filter(array_map(fn (array $v): string => trim((string) ($v['sku'] ?? '')), $variants))));
        $sku = $skus[0] ?? Vocabulary::sku($title);
        $set('identity.product_id', 'catalog');
        $set('identity.variant_ids', 'variant');
        $set('identity.sku', $skus !== [] ? 'variant' : ($sku !== null ? 'catalog_text' : 'unavailable'));
        foreach (['handle', 'vendor', 'product_type', 'tags'] as $field) {
            $set('identity.'.$field, 'catalog');
        }
        $set('identity.collections', $collections !== [] ? 'catalog' : 'unavailable');

        // variants and options
        $options = [];
        $metaVariants = [];
        foreach ($variants as $v) {
            $chosen = [];
            foreach ($optionNames as $i => $name) {
                $value = $v['option'.($i + 1)] ?? null;
                if ($value !== null && $value !== 'Default Title') {
                    $chosen[$name] = $value;
                    $options[$name][] = $value;
                }
            }
            $grams = (float) ($v['grams'] ?? 0);
            $metaVariants[] = [
                'variant_id' => (string) $v['id'],
                'sku' => trim((string) ($v['sku'] ?? '')) ?: null,
                'title' => (string) $v['title'],
                'price' => self::money($v['price'] ?? null),
                'compare_at_price' => self::money($v['compare_at_price'] ?? null),
                'available' => isset($v['available']) ? (bool) $v['available'] : null,
                'options' => $chosen,
                'weight' => $grams > 0 ? $grams : null,
                'weight_unit' => $grams > 0 ? 'g' : null,
            ];
        }
        $options = array_map(fn (array $values): array => array_values(array_unique($values)), $options);
        $set('variants', 'variant');

        // commerce
        $prices = array_values(array_filter(array_column($metaVariants, 'price'), 'is_numeric'));
        $available = array_filter($metaVariants, fn (array $v): bool => $v['available'] === true);
        $forSale = $available !== [] ? array_values($available) : $metaVariants;
        $compare = array_values(array_filter(array_column($metaVariants, 'compare_at_price'), 'is_numeric'));
        $commerce = [
            'price' => $forSale === [] ? null : min(array_filter(array_column($forSale, 'price'), 'is_numeric') ?: [null]),
            'compare_at_price' => $compare === [] ? null : max($compare),
            'currency' => $currency,
            'price_min' => $prices === [] ? null : min($prices),
            'price_max' => $prices === [] ? null : max($prices),
            'available' => $metaVariants === [] ? null : $available !== [],
            'inventory_quantity' => null,
        ];
        foreach (['price', 'compare_at_price', 'price_min', 'price_max', 'available'] as $field) {
            $set('commerce.'.$field, 'variant');
        }
        $set('commerce.currency', 'catalog');
        $set('commerce.inventory_quantity', 'unavailable');

        // physical
        $variantGrams = array_values(array_filter(array_column($metaVariants, 'weight')));
        $textWeight = Vocabulary::weightGrams($text);
        $dimensions = Vocabulary::dimensions($text);
        $sizes = [];
        foreach ($options as $name => $values) {
            if (preg_match(self::SIZE_OPTION, $name) === 1) {
                $sizes = [...$sizes, ...$values];
            }
        }
        $physical = [
            'weight' => $variantGrams !== [] ? min($variantGrams) : ($textWeight['value'] ?? null),
            'weight_unit' => $variantGrams !== [] || $textWeight !== null ? 'g' : null,
            'weight_raw' => $variantGrams !== [] ? null : ($textWeight['raw'] ?? null),
            'length' => $dimensions['length']['value'] ?? null,
            'width' => $dimensions['width']['value'] ?? null,
            'height' => $dimensions['height']['value'] ?? null,
            'diameter' => $dimensions['diameter']['value'] ?? null,
            'dimension_unit' => $dimensions !== [] ? 'cm' : null,
            'dimensions_raw' => array_values(array_unique(array_column($dimensions, 'raw'))),
            'size' => count($sizes) === 1 ? $sizes[0] : null,
            'sizes' => $sizes,
            'volume' => null,
            'volume_unit' => null,
        ];
        $set('physical.weight', $variantGrams !== [] ? 'variant' : ($textWeight !== null ? 'catalog_text' : 'unavailable'));
        if ($dimensions !== []) {
            $set('physical.dimensions', 'catalog_text');
        }
        if ($sizes !== []) {
            $set('physical.sizes', 'variant');
        }

        // attributes: the options the store named, then what code reads in its words
        $read = Vocabulary::read($text);
        foreach ($options as $name => $values) {
            if (preg_match(self::COLOR_OPTION, $name) === 1) {
                $read['color'] = array_values(array_unique([...$read['color'], ...Vocabulary::read(implode(' ', $values), ['color'])['color']]));
            } elseif (preg_match(self::MATERIAL_OPTION, $name) === 1) {
                $read['material'] = array_values(array_unique([...$read['material'], ...Vocabulary::read(implode(' ', $values), ['material'])['material']]));
            }
        }
        $karat = Vocabulary::karat($text);
        $stoneCarats = Vocabulary::stoneCarats($text);
        $otherOptions = array_filter($options, fn (string $name): bool => preg_match(self::SIZE_OPTION, $name) !== 1 && preg_match(self::COLOR_OPTION, $name) !== 1 && preg_match(self::MATERIAL_OPTION, $name) !== 1, ARRAY_FILTER_USE_KEY);
        $attributes = [
            'color' => $read['color'],
            'material' => $read['material'],
            'shape' => $read['shape'],
            'pattern' => $read['pattern'],
            'style' => $read['style'],
            'finish' => $read['finish'],
            'gender' => $read['gender'],
            'age_group' => $read['age_group'],
            'occasion' => $read['occasion'],
            'features' => [],
            'custom' => array_filter([
                'stones' => $read['stone'],
                'karat' => $karat,
                'stone_carats' => $stoneCarats,
                'options' => $otherOptions,
            ], fn ($v): bool => $v !== null && $v !== []),
        ];
        foreach (['color', 'material', 'shape', 'pattern', 'style', 'finish', 'gender', 'age_group', 'occasion'] as $field) {
            if ($attributes[$field] !== []) {
                $set('attributes.'.$field, 'catalog_text');
            }
        }
        foreach (array_keys($attributes['custom']) as $field) {
            $set('attributes.custom.'.$field, $field === 'options' ? 'variant' : 'catalog_text');
        }

        $normalized = [];
        $synonyms = [];
        foreach ($read as $group => $values) {
            foreach ($values as $value) {
                $normalized[] = $group.':'.$value;
                $synonyms = [...$synonyms, ...Vocabulary::words($group, $value)];
            }
        }
        $set('text.normalized_terms', 'generated');
        $set('text.synonyms', 'generated');

        return [
            'identity' => [
                'product_id' => (string) $product['id'],
                'variant_ids' => array_column($metaVariants, 'variant_id'),
                'sku' => $sku,
                'barcode' => null,
                'handle' => (string) $product['handle'],
                'vendor' => trim((string) ($product['vendor'] ?? '')),
                'brand' => null,
                'product_type' => $type,
                'product_type_normalized' => $read['type'][0] ?? null,
                'categories' => $type !== '' ? [$type] : [],
                'collections' => $collections,
                'tags' => $tags,
            ],
            'text' => [
                'title' => $title,
                'subtitle' => null,
                'description' => $description,
                'short_description' => mb_substr(preg_split('/(?<=[.!?])\s/u', $description)[0] ?? '', 0, 200),
                'search_text' => trim($title.' '.$description),
                'keywords' => $tags,
                'synonyms' => array_values(array_unique($synonyms)),
                'normalized_terms' => $normalized,
            ],
            'commerce' => $commerce,
            'variants' => $metaVariants,
            'physical' => $physical,
            'attributes' => $attributes,
            'image_analysis' => null,
            'search' => null,
            'vectors' => null,
            'provenance' => [
                'sources' => $sources,
                'unavailable' => ['metafields', 'inventory_quantity', 'barcode', 'brand'],
                'prompt_version' => null,
                'generated_at' => gmdate('c'),
            ],
            'source' => [
                'url' => rtrim((string) ($product['__shop'] ?? ''), '/').'/products/'.$product['handle'],
                'image_url' => (string) ($product['images'][0]['src'] ?? ''),
            ],
        ];
    }

    /**
     * A model's answer, kept to the shape and the vocabulary. What did not fit is dropped and named
     * in issues, so the evaluation counts it.
     *
     * @return array{analysis: array<string, mixed>, issues: list<string>}
     */
    public static function imageAnalysis(array $data, string $provider, string $model): array
    {
        $issues = [];
        $analysis = [
            'caption' => is_string($data['caption'] ?? null) ? trim(mb_substr($data['caption'], 0, 500)) : '',
            'product_type_visible' => null,
        ];

        if ($analysis['caption'] === '') {
            $issues[] = 'missing:caption';
        }

        $type = $data['product_type_visible'] ?? null;
        if (is_string($type) && in_array($type, Vocabulary::values('type'), true)) {
            $analysis['product_type_visible'] = $type;
        } elseif ($type !== null && $type !== 'other') {
            $issues[] = 'invalid:product_type_visible='.(is_scalar($type) ? $type : gettype($type));
        }

        foreach (self::IMAGE_LISTS as $key => $group) {
            $analysis[$key] = [];
            if (! array_key_exists($key, $data)) {
                $issues[] = 'missing:'.$key;

                continue;
            }
            if (! is_array($data[$key])) {
                $issues[] = 'type:'.$key;

                continue;
            }
            foreach ($data[$key] as $value) {
                if (is_string($value) && in_array($value, Vocabulary::values($group), true)) {
                    $analysis[$key][] = $value;
                } else {
                    $issues[] = 'invalid:'.$key.'='.(is_scalar($value) ? $value : gettype($value));
                }
            }
            $analysis[$key] = array_values(array_unique($analysis[$key]));
        }

        foreach (['visible_features', 'objects', 'visual_keywords', 'image_search_terms'] as $key) {
            $list = $data[$key] ?? null;
            if (! is_array($list)) {
                $issues[] = ($list === null ? 'missing:' : 'type:').$key;
                $list = [];
            }
            $analysis[$key] = array_values(array_unique(array_filter(array_map(fn ($w): string => is_string($w) ? trim(mb_substr($w, 0, 60)) : '', array_slice($list, 0, 20)))));
        }

        // A number with a unit, a price or a code in the words: read from the picture, it is made up.
        foreach (array_merge($analysis['visual_keywords'], $analysis['image_search_terms'], $analysis['visible_features']) as $word) {
            if (preg_match('/\d\s*(גרם|קארט|קראט|ס"מ|מ"מ|₪|ש"ח|k\b|ct\b|cm\b|mm\b)|מק"?ט/iu', $word) === 1) {
                $issues[] = 'spec:'.$word;
            }
        }
        foreach (self::NOT_FROM_PICTURE as $key) {
            if (array_key_exists($key, $data)) {
                $issues[] = 'spec:'.$key;
            }
        }

        $confidence = is_array($data['confidence'] ?? null) ? $data['confidence'] : [];
        $analysis['confidence'] = array_map(fn ($c): ?float => is_numeric($c) ? max(0.0, min(1.0, (float) $c)) : null, $confidence);
        $analysis['provider'] = $provider;
        $analysis['model'] = $model;

        return ['analysis' => $analysis, 'issues' => $issues];
    }

    /** The catalogue's meta object with the picture's reading added, and its search section. */
    public static function withImage(array $meta, ?array $analysis): array
    {
        $meta['image_analysis'] = $analysis;
        $meta['provenance']['prompt_version'] = $analysis === null ? null : self::PROMPT_VERSION;

        if ($analysis !== null) {
            foreach (['caption', 'product_type_visible', ...array_keys(self::IMAGE_LISTS), 'visible_features', 'objects', 'visual_keywords', 'image_search_terms'] as $key) {
                $meta['provenance']['sources']['image_analysis.'.$key] = 'image';
            }
        }

        $meta['search'] = self::search($meta, $analysis);
        $meta['provenance']['sources']['search'] = 'generated';
        $meta['vectors'] = ['text_embedding' => null, 'image_embedding' => null, 'combined_embedding' => null];

        return $meta;
    }

    /** The search section: filters from the catalogue, filled from the picture only where it is silent. */
    private static function search(array $meta, ?array $analysis): array
    {
        $attributes = $meta['attributes'];
        $custom = $attributes['custom'];
        $filters = [];
        $pick = function (string $name, array $catalog, array $image) use (&$filters): void {
            $filters[$name] = $catalog !== [] ? $catalog : $image;
            $filters[$name.'_source'] = $catalog !== [] ? 'catalog' : ($image !== [] ? 'image' : null);
        };

        $catalogType = $meta['identity']['product_type_normalized'];
        $pick('product_type', $catalogType !== null ? [$catalogType] : [], ($analysis['product_type_visible'] ?? null) !== null ? [$analysis['product_type_visible']] : []);
        $pick('color', $attributes['color'], $analysis['visible_colors'] ?? []);
        $pick('material', $attributes['material'], $analysis['visible_materials'] ?? []);
        $pick('stone', $custom['stones'] ?? [], $analysis['visible_stones'] ?? []);
        $pick('shape', $attributes['shape'], $analysis['visible_shapes'] ?? []);
        $pick('style', $attributes['style'], $analysis['visible_styles'] ?? []);
        $pick('pattern', $attributes['pattern'], $analysis['visible_patterns'] ?? []);
        $filters['sizes'] = $meta['physical']['sizes'];
        $filters['available'] = $meta['commerce']['available'];
        $filters['vendor'] = $meta['identity']['vendor'];
        $filters['collections'] = $meta['identity']['collections'];
        $filters['tags'] = $meta['identity']['tags'];
        $filters['karat'] = $custom['karat'] ?? null;
        $filters['gender'] = $attributes['gender'];
        $filters['occasion'] = $attributes['occasion'];

        $numeric = array_filter([
            'price' => $meta['commerce']['price'],
            'price_min' => $meta['commerce']['price_min'],
            'price_max' => $meta['commerce']['price_max'],
            'compare_at_price' => $meta['commerce']['compare_at_price'],
            'weight_g' => $meta['physical']['weight'],
            'karat' => $custom['karat'] ?? null,
            'stone_carat_total' => isset($custom['stone_carats']) ? array_sum($custom['stone_carats']) : null,
            'length_cm' => $meta['physical']['length'],
            'width_cm' => $meta['physical']['width'],
            'height_cm' => $meta['physical']['height'],
            'diameter_cm' => $meta['physical']['diameter'],
        ], fn ($v): bool => $v !== null);

        $variantWeights = array_values(array_filter(array_column($meta['variants'], 'weight')));
        $ranges = array_filter([
            'price' => $meta['commerce']['price_min'] === null ? null : ['min' => $meta['commerce']['price_min'], 'max' => $meta['commerce']['price_max']],
            'weight_g' => $variantWeights === [] ? null : ['min' => min($variantWeights), 'max' => max($variantWeights)],
        ]);

        // Hebrew words for what the filters hold, so meaning search and spelling search see them too.
        $labels = [];
        foreach (['product_type' => 'type', 'color' => 'color', 'material' => 'material', 'stone' => 'stone', 'shape' => 'shape', 'style' => 'style', 'pattern' => 'pattern'] as $field => $group) {
            foreach ($filters[$field] as $value) {
                $labels[] = Vocabulary::hebrew($group, $value);
            }
        }

        $semantic = implode('. ', array_filter([
            $meta['text']['title'],
            $meta['identity']['product_type'],
            $meta['text']['description'],
            implode(', ', $meta['identity']['tags']),
            implode(', ', $meta['identity']['collections']),
            isset($custom['karat']) ? 'זהב '.$custom['karat'].' קארט' : null,
            $filters['sizes'] !== [] ? 'מידות '.implode(', ', $filters['sizes']) : null,
        ]));
        $imageText = $analysis === null ? '' : implode('. ', array_filter([
            $analysis['caption'],
            implode(', ', $analysis['visible_features']),
            implode(', ', $analysis['visual_keywords']),
        ]));
        $combined = implode('. ', array_filter([
            $semantic,
            implode(', ', array_unique($labels)),
            $imageText,
            $analysis === null ? '' : implode(', ', $analysis['image_search_terms']),
        ]));

        $exact = array_values(array_unique(array_filter([
            $meta['identity']['sku'],
            $meta['identity']['handle'],
            $meta['identity']['product_id'],
            ...array_column($meta['variants'], 'sku'),
            isset($custom['karat']) ? $custom['karat'].'k' : null,
        ])));

        $words = array_values(array_unique(array_filter(explode(' ', HebrewSearch::normalize($combined)), fn (string $w): bool => mb_strlen($w) > 1)));

        return [
            'combined_search_text' => $combined,
            'exact_terms' => $exact,
            'fuzzy_terms' => $words,
            'filterable_fields' => $filters,
            'numeric_fields' => $numeric,
            'range_fields' => $ranges,
            'semantic_text' => $semantic,
            'image_semantic_text' => $imageText,
        ];
    }

    private static function plain(string $html): string
    {
        $text = (string) preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|tr)>#iu', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function money(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
