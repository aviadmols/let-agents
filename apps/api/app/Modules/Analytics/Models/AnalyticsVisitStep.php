<?php

namespace App\Modules\Analytics\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a visit: a page seen, a search (its words and how many results), a photo search, a
 * question, the search window opened, a save to the list, an add to cart, a purchase, details left.
 * Deleted with its visit.
 *
 * @property int $id
 * @property string $shop_id
 * @property int $visit_id
 * @property string $step_id
 * @property string $kind
 * @property Carbon $at
 * @property string|null $path
 * @property string|null $page_type
 * @property string|null $item the product it was about
 * @property string|null $label the words searched or asked
 * @property int|null $number results, or quantity
 * @property string|null $value money
 */
class AnalyticsVisitStep extends Model
{
    use BelongsToTenant;

    public const KINDS = ['view', 'search', 'photo', 'ask', 'open', 'heart', 'cart', 'order', 'lead'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['at' => 'datetime', 'number' => 'integer'];
    }

    /** @return BelongsTo<AnalyticsVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(AnalyticsVisit::class, 'visit_id');
    }
}
