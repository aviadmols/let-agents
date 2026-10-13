<?php

namespace App\Modules\Shortlist\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A day's count of something shoppers did with compare: a comparison opened (item ''), or a
 * product in one.
 *
 * @property int $id
 * @property string $shop_id
 * @property Carbon $day
 * @property string $kind
 * @property string $item
 * @property int $n
 */
class ShortlistCount extends Model
{
    use BelongsToTenant;

    public const COMPARE = 'compare';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'date', 'n' => 'integer'];
    }

    /**
     * One more, today. Inside the shop's tenant.
     *
     * @param  list<string>  $items
     */
    public static function add(string $shopId, string $kind, array $items): void
    {
        $day = now()->toDateString();

        foreach (['', ...$items] as $item) {
            self::query()->insertOrIgnore(['shop_id' => $shopId, 'day' => $day, 'kind' => $kind, 'item' => $item, 'n' => 0, 'created_at' => now(), 'updated_at' => now()]);
            self::query()->whereDate('day', $day)->where('kind', $kind)->where('item', $item)->increment('n');
        }
    }
}
