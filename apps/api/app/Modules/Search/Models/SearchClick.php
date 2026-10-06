<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One result clicked for one query on one day.
 *
 * @property string $id
 * @property string $shop_id
 * @property Carbon $day
 * @property string $query
 * @property string $item
 * @property string $title
 * @property int $clicks
 */
class SearchClick extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'date', 'clicks' => 'integer'];
    }
}
