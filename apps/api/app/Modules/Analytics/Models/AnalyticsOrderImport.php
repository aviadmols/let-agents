<?php

namespace App\Modules\Analytics\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How far a shop's past orders have arrived from the plugin. One row per shop.
 *
 * @property int $id
 * @property string $shop_id
 * @property int|null $expected what the plugin counted before sending, when it said
 * @property int $received
 * @property int $stored new orders (one already known is received but not stored again)
 * @property int $refused orders that failed validation
 * @property Carbon|null $oldest_ordered_at
 * @property Carbon|null $newest_ordered_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at set when the plugin says it sent the last page
 */
class AnalyticsOrderImport extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expected' => 'integer',
            'received' => 'integer',
            'stored' => 'integer',
            'refused' => 'integer',
            'oldest_ordered_at' => 'datetime',
            'newest_ordered_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
