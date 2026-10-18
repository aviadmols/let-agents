<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A question the team fixed, kept as a regression case: asked again before a change goes out and
 * checked in code against what is expected of it (QualityExpect).
 *
 * @property string $id
 * @property string $shop_id
 * @property string|null $ask_id the ask it was made from
 * @property string $question
 * @property list<string>|null $products external ids shown with it
 * @property array{outcome?: string, contains?: list<string>, absent?: list<string>, picks?: list<string>} $expect
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $last_run_at
 * @property bool|null $last_passed
 * @property list<string>|null $last_problems
 * @property string|null $last_answer
 * @property string|null $last_outcome
 * @property int|null $last_prompt_version
 * @property float|null $last_cost_usd
 */
class AssistantQualityCase extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'expect' => 'array',
            'last_problems' => 'array',
            'last_run_at' => 'datetime',
            'last_passed' => 'boolean',
            'last_prompt_version' => 'integer',
            'last_cost_usd' => 'float',
        ];
    }
}
