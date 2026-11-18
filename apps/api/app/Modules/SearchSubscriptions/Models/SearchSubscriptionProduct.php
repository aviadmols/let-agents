<?php

namespace App\Modules\SearchSubscriptions\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A product sent to an address in a store: it is never sent to that address again.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $email_hash
 * @property string $product_id
 * @property string|null $subscription_id
 * @property Carbon $sent_at
 */
class SearchSubscriptionProduct extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
