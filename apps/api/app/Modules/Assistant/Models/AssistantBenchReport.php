<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One shop's part of a benchmark run (see BenchReports): the summary, and one row per question.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $kind factsheet or retrieval
 * @property string $label
 * @property int $questions
 * @property array<string, mixed> $summary
 * @property list<array<string, mixed>> $rows
 */
class AssistantBenchReport extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const FACTSHEET = 'factsheet';

    public const RETRIEVAL = 'retrieval';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'rows' => 'array', 'questions' => 'integer'];
    }
}
