<?php

namespace App\Modules\SearchSubscriptions\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One email of Search Subscriptions to one address of one store (weekly, or the reminder before the end), and whether
 * it was opened or clicked: weekly ones unopened in a row stop the address's subscriptions in the store.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $email_hash
 * @property string $kind weekly or remind
 * @property list<string> $queries
 * @property int $products
 * @property Carbon $sent_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $clicked_at
 */
class SearchSubscriptionSend extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['queries' => 'array', 'sent_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime'];
    }
}
