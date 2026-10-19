<?php

namespace App\Modules\Assistant\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One run of the quality cases, of one shop or several: on each case's frozen data or on today's, with
 * the writer prompt at a version, its numbers (QualityRunTotals) and each case's result. Not a tenant's
 * row: a baseline run spans shops; it is read on the operator's screen only.
 *
 * @property string $id
 * @property string|null $shop_id
 * @property string|null $label
 * @property string $mode frozen or live
 * @property string|null $kind regression, baseline, or null for both
 * @property int|null $prompt_version
 * @property array<string, mixed>|null $totals
 * @property int|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class AssistantQualityRun extends Model
{
    use HasUlids;

    public const FROZEN = 'frozen';

    public const LIVE = 'live';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'prompt_version' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<AssistantQualityResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(AssistantQualityResult::class, 'run_id');
    }
}
