<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a shop's search box searches, as one list rebuilt every night.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $hash
 * @property string $items
 * @property array<string, int> $counts
 * @property Carbon $built_at
 */
class SearchIndex extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'search_indexes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'built_at' => 'datetime'];
    }
}
