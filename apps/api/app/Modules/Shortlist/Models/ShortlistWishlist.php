<?php

namespace App\Modules\Shortlist\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One shopper's wishlist in one shop (see the migration): a browser's, or a store account's.
 *
 * @property string $id
 * @property string $shop_id
 * @property string|null $token_hash
 * @property string|null $customer_ref
 * @property string $share_code
 * @property list<string> $items the store's product ids, newest first
 */
class ShortlistWishlist extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }
}
