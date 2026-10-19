<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shopper's "Helpful?" on an assistant's answer in the search drawer: the thumb they pressed last.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $ask_id the question asked (AssistantSearchAsk)
 * @property bool $helpful
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SearchAnswerRating extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['helpful' => 'boolean'];
    }

    /** @return BelongsTo<AssistantSearchAsk, $this> */
    public function ask(): BelongsTo
    {
        return $this->belongsTo(AssistantSearchAsk::class, 'ask_id');
    }
}
