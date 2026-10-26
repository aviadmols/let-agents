<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What shoppers did with a search on a day: opened or added a product (value its id), took a chip off or put one on
 * (value its words), chose a looser search (value its words), asked the AI after it. Each shopper counted once a day.
 *
 * @property string $id
 * @property string $shop_id
 * @property Carbon $day
 * @property string $kind
 * @property string $query
 * @property string $value
 * @property int $count
 * @property int $visitors
 */
class SearchSignal extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const KINDS = ['click', 'add', 'chip_off', 'chip_on', 'loose_pick', 'ask_after'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'date', 'count' => 'integer', 'visitors' => 'integer'];
    }
}
