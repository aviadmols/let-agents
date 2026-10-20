<?php

namespace App\Modules\Catalog\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A product as the store feed describes it. Price and stock are for filtering and ranking
 * only; visitors always see live values.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $external_id
 * @property string $type
 * @property string $status
 * @property string $title
 * @property string|null $url
 * @property string|null $sku
 * @property string|null $brand
 * @property string|null $price
 * @property string|null $regular_price
 * @property string|null $currency
 * @property bool $on_sale
 * @property bool $in_stock
 * @property bool $purchasable
 * @property string|null $image_url
 * @property int $variations_count
 * @property Carbon|null $source_updated_at
 * @property string $hash
 * @property array<string, mixed> $payload
 * @property Carbon|null $synced_at
 * @property Carbon|null $removed_at
 */
class CatalogProduct extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    /** Store custom fields that describe the product itself, in "feature title / feature info" pairs. */
    private const SPEC_FIELD_PATTERN = '/^product_feature_(\d+)_(title|info)$/';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'price' => 'decimal:2',
            'regular_price' => 'decimal:2',
            'on_sale' => 'boolean',
            'in_stock' => 'boolean',
            'purchasable' => 'boolean',
            'variations_count' => 'integer',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsToMany<CatalogCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CatalogCategory::class, 'catalog_category_product', 'product_id', 'category_id');
    }

    /** @return HasMany<CatalogVariant, $this> in the store's order */
    public function variants(): HasMany
    {
        return $this->hasMany(CatalogVariant::class, 'product_id')->orderBy('position');
    }

    /** @param Builder<CatalogProduct> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * Products in any of the given categories.
     *
     * @param  Builder<CatalogProduct>  $query
     * @param  list<string>  $externalIds
     */
    public function scopeInCategories(Builder $query, array $externalIds): void
    {
        $query->whereHas('categories', fn (Builder $categories) => $categories->whereIn('catalog_categories.external_id', $externalIds));
    }

    public function shortDescription(): string
    {
        return (string) ($this->payload['short_description'] ?? '');
    }

    public function description(): string
    {
        return (string) ($this->payload['description'] ?? '');
    }

    /**
     * The product's measurements as the store gives them: its size ("30 × 20 × 10 cm") and its
     * weight ("2.5 kg"), each null when the store leaves it empty.
     *
     * @return array{size: string|null, weight: string|null}
     */
    public function measurements(): array
    {
        $d = (array) ($this->payload['dimensions'] ?? []);
        $value = fn (string $key): string => (string) preg_replace('/^\s+|\s+$/u', '', (string) ($d[$key] ?? ''));
        $sides = array_values(array_filter([$value('length'), $value('width'), $value('height')], fn (string $v): bool => $v !== '' && (float) $v > 0));
        $weight = $value('weight');

        return [
            'size' => $sides === [] ? null : implode(' × ', $sides).($value('dimension_unit') !== '' ? ' '.$value('dimension_unit') : ''),
            'weight' => $weight === '' || (float) $weight <= 0 ? null : $weight.($value('weight_unit') !== '' ? ' '.$value('weight_unit') : ''),
        ];
    }

    /**
     * An option's name as a shopper reads it: its title (Shopify), or what its attributes are
     * (WooCommerce variations come with attributes and no title): "גוון: אלון · אורך: 3.68 מטר".
     * An attribute left open ("any") says only its name.
     *
     * @param  array<string, mixed>  $variant  one of the payload's variations
     */
    public static function variantLabel(array $variant): string
    {
        $title = trim((string) ($variant['title'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        $parts = array_map(function ($attribute): string {
            $name = trim((string) ($attribute['name'] ?? $attribute['key'] ?? ''));
            $value = trim((string) ($attribute['value'] ?? ''));

            return $value === '' ? $name : ($name === '' ? $value : $name.': '.$value);
        }, array_filter((array) ($variant['attributes'] ?? []), 'is_array'));

        return implode(' · ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /** @return list<list<string>> every category path the product is listed under */
    public function categoryPaths(): array
    {
        return array_values(array_map(
            fn (array $category): array => array_values(array_map('strval', (array) ($category['path'] ?? []))),
            array_filter((array) ($this->payload['categories'] ?? []), 'is_array'),
        ));
    }

    /**
     * What makes this product another kind than its neighbours, for a sample that misses none: each
     * category it is in (its whole path), its type in the store (simple, variable, a subscription),
     * its product type (Shopify) and the set of choices it is sold with ("ring size", "length").
     *
     * @return list<string>
     */
    public function kindMarks(): array
    {
        $choices = array_map(fn (array $a): string => mb_strtolower(trim($a['name'])), array_filter($this->storeAttributes(), fn (array $a): bool => $a['for_variations']));
        sort($choices);
        $kind = trim((string) ($this->payload['product_type'] ?? ''));

        return array_values(array_unique(array_filter([
            'type:'.$this->type,
            'choices:'.implode('+', $choices),
            $kind === '' ? null : 'kind:'.mb_strtolower($kind),
            ...array_map(fn (array $path): string => 'category:'.mb_strtolower(implode('/', $path)), $this->categoryPaths()),
        ])));
    }

    /**
     * The fewest products that between them show every kind (kindMarks), at most $max, in stock first
     * among equals: ten necklaces show nothing of what a ring asks, one of each does.
     *
     * @param  iterable<CatalogProduct>  $products
     * @return array{0: list<CatalogProduct>, 1: int} the products, and how many kinds they show
     */
    public static function spread(iterable $products, int $max): array
    {
        $marks = [];
        $byId = [];
        foreach ($products as $product) {
            $marks[(string) $product->id] = [$product->kindMarks(), (bool) $product->in_stock];
            $byId[(string) $product->id] = $product;
        }
        [$ids, $shown] = self::cover($marks, $max);

        return [array_map(fn (string $id): CatalogProduct => $byId[$id], $ids), $shown];
    }

    /**
     * spread() on the kinds alone, for a catalogue too large to hold: product id => [its kindMarks(), in stock].
     *
     * @param  array<string, array{0: list<string>, 1: bool}>  $marks
     * @return array{0: list<string>, 1: int} the product ids, and how many kinds they show
     */
    public static function cover(array $marks, int $max): array
    {
        $own = array_map(fn (array $m): array => array_flip($m[0]), $marks);
        $open = $own === [] ? [] : array_fill_keys(array_keys(array_merge(...array_values($own))), true);
        $all = count($open);
        $picked = [];

        while ($open !== [] && count($picked) < $max) {
            [$best, $bestGain] = [null, 0];
            foreach ($own as $id => $kinds) {
                $gain = isset($picked[$id]) ? 0 : count(array_intersect_key($kinds, $open)) * 2 + ($marks[$id][1] ? 1 : 0);
                if ($gain > $bestGain) {
                    [$best, $bestGain] = [(string) $id, $gain];
                }
            }
            if ($best === null || $bestGain < 2) {
                break;
            }
            $picked[$best] = true;
            $open = array_diff_key($open, $own[$best]);
        }

        return [array_map('strval', array_keys($picked)), $all - count($open)];
    }

    /**
     * Attributes as the store holds them, one line each: "name: value, value".
     *
     * @return list<array{name: string, values: list<string>, for_variations: bool}>
     */
    public function storeAttributes(): array
    {
        $attributes = [];

        foreach ((array) ($this->payload['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $values = array_values(array_filter(array_map('strval', (array) ($attribute['values'] ?? [])), fn (string $v): bool => $v !== ''));

            if ($values === []) {
                continue;
            }

            $attributes[] = [
                'name' => (string) ($attribute['name'] ?? $attribute['key'] ?? ''),
                'values' => $values,
                'for_variations' => (bool) ($attribute['used_for_variations'] ?? false),
            ];
        }

        return $attributes;
    }

    /**
     * Spec fields the store keeps outside WooCommerce attributes, as "title: info" lines.
     *
     * @return list<string>
     */
    public function specFields(): array
    {
        $pairs = [];

        foreach ((array) ($this->payload['meta'] ?? []) as $key => $value) {
            if (is_string($key) && preg_match(self::SPEC_FIELD_PATTERN, $key, $match) && is_scalar($value) && trim((string) $value) !== '') {
                $pairs[(int) $match[1]][$match[2]] = trim((string) $value);
            }
        }

        ksort($pairs);

        return array_values(array_map(
            fn (array $pair): string => trim(($pair['title'] ?? '').': '.($pair['info'] ?? ''), ': '),
            $pairs,
        ));
    }

    /** @return list<array{type: string, target: string}> upsells and cross-sells the merchant set */
    public function merchantRelations(): array
    {
        return array_values(array_map(
            fn (array $relation): array => ['type' => (string) $relation['type'], 'target' => (string) $relation['target']],
            array_filter((array) ($this->payload['relations'] ?? []), fn ($r): bool => is_array($r) && isset($r['type'], $r['target'])),
        ));
    }
}
