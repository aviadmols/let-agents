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
 * @property string $digest the report in the language it ran in (the one mailed)
 * @property array<string, string>|null $digests the same report in each of the panel's languages, by locale
 * @property bool $mailed
 * @property string|null $run_id
 */
class ImprovementReview extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const QUIET = 'quiet';

    /** Too few searches since the last review to think about: nothing gathered, no model asked. */
    public const WAITING = 'waiting';

    public const REVIEWED = 'reviewed';

    public const STOPPED = 'stopped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'date', 'evidence' => 'array', 'digests' => 'array', 'mailed' => 'boolean'];
    }

    /** The report in the reader's language; one written before there were languages, as it was written. */
    public function digestIn(string $locale): string
    {
        return (string) (($this->digests[$locale] ?? null) ?: $this->digest);
    }

    /** @return HasMany<ImprovementProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(ImprovementProposal::class, 'review_id');
    }
}
