<?php

namespace App\Modules\Shortlist\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One shopper's wishlist in one shop (see the migrations): a browser's, a store account's, or a
 * phone number's (customer_ref "phone:<key>", signed in with a code by SMS).
 *
 * @property string $id
 * @property string $shop_id
 * @property string|null $token_hash
 * @property string|null $customer_ref
 * @property string $share_code
 * @property list<string> $items the store's product ids, newest first
 * @property string|null $phone decrypted on read: 05XXXXXXXX, for a list kept by phone
 * @property bool $marketing_consent the shopper agreed to hear from the store
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $updated_at
 */
class ShortlistWishlist extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['phone'];

    protected function casts(): array
    {
        return ['items' => 'array', 'phone' => 'encrypted', 'marketing_consent' => 'boolean', 'phone_verified_at' => 'datetime'];
    }
}
