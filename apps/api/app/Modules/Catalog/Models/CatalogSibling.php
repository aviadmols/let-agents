<?php

namespace App\Modules\Catalog\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A product and the group of one model's versions it belongs to, as the store's collection pages link them
 * (ReadCollectionSiblings), with how the page names it ("Citrine", "Julius - BA6 Soft Black").
 *
 * @property string $id
 * @property string $shop_id
 * @property string $product_id
 * @property string $group_key
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CatalogSibling extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];
}
