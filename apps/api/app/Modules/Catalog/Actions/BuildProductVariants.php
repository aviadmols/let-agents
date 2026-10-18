<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\CatalogColourName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Catalog\Models\CatalogVariant;
use App\Modules\Catalog\Support\VariantRows;
use Illuminate\Support\Str;

/**
 * A product's variant rows, built again from its feed record (VariantRows). The catalog sync calls
 * it for every product it creates or changes; the nightly read also for products that have
 * variants and no rows yet (a product unchanged since before the rows existed). Code alone, inside
 * the shop's tenant.
 */
final class BuildProductVariants
{
    /** @var array<string, array<string, array{shades: list<string>, pattern: string|null}>> each shop's placed colour names, read once */
    private array $learned = [];

    /** @return int the rows the product has now */
    public function product(CatalogProduct $product): int
    {
        $this->learned[$product->shop_id] ??= CatalogColourName::placed();
        $rows = VariantRows::for($product, $this->learned[$product->shop_id]);
        $now = now();

        CatalogVariant::query()->where('product_id', $product->id)->delete();

        if ($rows === []) {
            return 0;
        }

        // The same variant twice in one record would break the unique key; the first one stands.
        $rows = array_values(array_column(array_reverse($rows), null, 'external_id'));
        usort($rows, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        CatalogVariant::query()->insert(array_map(fn (array $row): array => [
            'id' => (string) Str::ulid(),
            'shop_id' => $product->shop_id,
            'product_id' => $product->id,
            'color_shades' => $row['color_shades'] === null ? null : json_encode($row['color_shades']),
            'color_families' => $row['color_families'] === null ? null : json_encode($row['color_families']),
            'options' => $row['options'] === [] ? null : json_encode($row['options'], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ] + $row, $rows));

        return count($rows);
    }

    /**
     * The products whose variants are named so, built again: their names were just placed.
     *
     * @param  list<string>  $names
     * @return list<string> the external ids of the products built
     */
    public function named(array $names): array
    {
        $this->learned = [];
        $built = [];

        CatalogProduct::query()->whereNull('removed_at')
            ->whereIn('id', CatalogVariant::query()->whereIn('color_label', $names)->select('product_id'))
            ->lazyById(200)
            ->each(function (CatalogProduct $product) use (&$built): void {
                $this->product($product);
                $built[] = (string) $product->external_id;
            });

        return $built;
    }

    /** @return int the products given rows: those with variants and none yet */
    public function missing(): int
    {
        $built = 0;

        CatalogProduct::query()
            ->whereNull('removed_at')
            ->where('variations_count', '>', 0)
            ->whereDoesntHave('variants')
            ->lazyById(200)
            ->each(function (CatalogProduct $product) use (&$built): void {
                $this->product($product);
                $built++;
            });

        return $built;
    }

    /** @return int the products built: every product in the shop with variants */
    public function all(): int
    {
        $built = 0;

        CatalogProduct::query()
            ->whereNull('removed_at')
            ->where('variations_count', '>', 0)
            ->lazyById(200)
            ->each(function (CatalogProduct $product) use (&$built): void {
                $this->product($product);
                $built++;
            });

        return $built;
    }
}
