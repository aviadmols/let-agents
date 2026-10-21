<?php

namespace App\Modules\Catalog\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a product's page asks for before "add to cart" that the feed does not carry (PageChoices),
 * as last read. An empty list: the page asks for nothing.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property list<array{key: string, label: string, kind: string, required: bool, values: list<array{value: string, label: string, price: float|null, meters?: float}>}> $fields
 * @property float|null $size the size the page says the product itself is ("מידה נוכחית: 54")
 * @property string|null $size_label how the page names it
 * @property list<float>|null $sizes every size the page offers it in, its own among them, when it offers others ("הגדלה ל 54")
 * @property string|null $form_hash
 * @property string|null $product_hash the product's hash when its page was read
 * @property Carbon|null $checked_at
 * @property Carbon|null $changed_at
 * @property int $failures pages in a row that could not be read
 */
class CatalogProductChoice extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'checked_at' => 'datetime',
            'changed_at' => 'datetime',
            'failures' => 'integer',
            'size' => 'float',
            'sizes' => 'array',
        ];
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'product_id');
    }

    /** A field the shopper must fill before the store takes the product into its cart. */
    public function required(): bool
    {
        return array_filter($this->fields, fn (array $field): bool => (bool) ($field['required'] ?? false)) !== [];
    }
}
