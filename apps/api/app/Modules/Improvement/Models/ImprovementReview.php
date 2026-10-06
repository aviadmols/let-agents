<?php

namespace App\Modules\Improvement\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One shop's daily review.
 *
 * @property string $id
 * @property string $shop_id
 * @property Carbon $day
 * @property string $status quiet (nothing new, no model asked), reviewed, or stopped (no key or budget)
 * @property array<string, mixed> $evidence
 * @property int $proposed
 * @property int $accepted
 * @property int $applied
 * @property string $digest
 * @property bool $mailed
 * @property string|null $run_id
 */
class ImprovementReview extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const QUIET = 'quiet';

    public const REVIEWED = 'reviewed';

    public const STOPPED = 'stopped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'date', 'evidence' => 'array', 'mailed' => 'boolean'];
    }

    /** @return HasMany<ImprovementProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(ImprovementProposal::class, 'review_id');
    }
}
