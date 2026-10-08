<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The daily report on the questions asked in the search box: a score out of 100, a few plain
 * sentences for the shop manager, what to improve, and a score for each question.
 *
 * @property string $id
 * @property string $shop_id
 * @property Carbon $day the day reviewed
 * @property int|null $score
 * @property string|null $summary
 * @property list<string>|null $improvements
 * @property list<array{ask_id: string, score: int, note: string}> $items
 * @property array{asks: int, answered: int, no_match: int, whatsapp_shown: int, whatsapp_clicked: int, picked: int} $counts
 * @property string $status reviewed, quiet (no questions) or failed
 * @property string|null $reviewed_by
 * @property string|null $run_id
 */
class AssistantAskReview extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const REVIEWED = 'reviewed';

    public const QUIET = 'quiet';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'score' => 'integer',
            'improvements' => 'array',
            'items' => 'array',
            'counts' => 'array',
        ];
    }
}
