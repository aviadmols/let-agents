<?php

namespace App\Modules\Assistant\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What one case came to in one run: the outcome, the checker's verdict, why it failed as code reads it,
 * whether it met what was expected (null for a baseline case without an expectation), the answer, the
 * products offered, the cost and the time.
 *
 * @property string $id
 * @property string $run_id
 * @property string $case_id
 * @property string $shop_id
 * @property string|null $outcome
 * @property string|null $verdict
 * @property string|null $failure
 * @property bool|null $passed
 * @property list<string>|null $problems
 * @property string|null $answer
 * @property list<string>|null $picks external ids
 * @property float|null $cost_usd
 * @property int|null $duration_ms
 */
class AssistantQualityResult extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'problems' => 'array',
            'picks' => 'array',
            'trace' => 'array',
            'cost_usd' => 'float',
            'duration_ms' => 'integer',
        ];
    }
}
