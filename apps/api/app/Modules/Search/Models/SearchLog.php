<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One search on a store's site, with what it found, what was clicked, and the visit it came from
 * (App\Core\Http\Visit). The address and the session are cleared after search.visit_keep_days.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $query
 * @property int $results
 * @property string|null $clicked the result clicked, as its record id ("p:123")
 * @property string|null $ip
 * @property string|null $device
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $session_id
 * @property string|null $page
 * @property string|null $referrer
 * @property Carbon|null $created_at
 */
class SearchLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['results' => 'integer'];
    }
}
