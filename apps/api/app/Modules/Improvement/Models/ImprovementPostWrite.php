<?php

namespace App\Modules\Improvement\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One post a model wrote for a store, a rewrite included: what the monthly number counts.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $proposal_id
 * @property string|null $run_id
 * @property Carbon $created_at
 */
class ImprovementPostWrite extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** How many posts a model wrote for the shop in the current calendar month. Inside the shop's tenant. */
    public static function thisMonth(): int
    {
        return self::query()->where('created_at', '>=', now()->startOfMonth())->count();
    }
}
