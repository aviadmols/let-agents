<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One normalized query on one day: how often it was searched, found nothing, and was clicked.
 *
 * @property string $id
 * @property string $shop_id
 * @property Carbon $day
 * @property string $query
 * @property int $searches
 * @property int $empty
 * @property int $clicks
 * @property int $last_results
 */
class SearchTerm extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'date', 'searches' => 'integer', 'empty' => 'integer', 'clicks' => 'integer', 'last_results' => 'integer'];
    }
}
