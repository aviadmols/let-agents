<?php

namespace App\Modules\Catalog\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalog\Enums\ColorFamily;
use App\Modules\Catalog\Enums\ColorShade;
use App\Modules\Catalog\Enums\FabricPattern;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One variant of a product, as VariantRows reads it from the feed record: its colour, pattern,
 * size and stock. Built again whenever the product changes; never edited on its own.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string $external_id
 * @property int $position
 * @property string $title
 * @property string|null $sku
 * @property string|null $color_label the store's own name for its colour
 * @property list<string>|null $color_shades ColorShade values
 * @property list<string>|null $color_families ColorFamily values
 * @property string|null $color_hex the store's swatch, else the shade's
 * @property string|null $pattern FabricPattern value
 * @property string|null $size_label
 * @property string|null $size_key
 * @property int|null $size_position where the size stands in the store's order
 * @property array<string, string>|null $options the other options chosen ("אורך שרוול: ארוך")
 * @property string|null $price
 * @property bool $in_stock
 * @property int|null $quantity only where the store counts it
 * @property bool $purchasable
 * @property string|null $image_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CatalogVariant extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'color_shades' => 'array',
            'color_families' => 'array',
            'size_position' => 'integer',
            'options' => 'array',
            'price' => 'decimal:2',
            'in_stock' => 'boolean',
            'quantity' => 'integer',
            'purchasable' => 'boolean',
        ];
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }

    /** @return list<ColorShade> */
    public function shades(): array
    {
        return array_values(array_filter(array_map(fn ($v) => ColorShade::tryFrom((string) $v), (array) $this->color_shades)));
    }

    /** @return list<ColorFamily> */
    public function families(): array
    {
        return array_values(array_filter(array_map(fn ($v) => ColorFamily::tryFrom((string) $v), (array) $this->color_families)));
    }

    public function fabricPattern(): ?FabricPattern
    {
        return $this->pattern === null ? null : FabricPattern::tryFrom($this->pattern);
    }
}
