<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One question asked in the search box, and what came of it.
 *
 * @property string $id
 * @property string $shop_id
 * @property Carbon $day
 * @property string $question
 * @property list<string> $products external ids of the products shown with the question
 * @property string $outcome answered, no_match, no_info, out_of_scope, limit or unavailable
 * @property string $from bank, model or none
 * @property string|null $answer_id
 * @property string|null $answer what was shown, when no saved answer holds it
 * @property list<array{external_id: string, title: string, why: string}>|null $picks
 * @property bool $whatsapp_shown
 * @property bool $whatsapp_clicked
 * @property list<string>|null $picked external ids of picked products the shopper opened or added
 * @property string|null $run_id the run that answered it: its calls and their ledger rows
 * @property int|null $prompt_version
 * @property string|null $writer_model
 * @property string|null $checker_model
 * @property string|null $verdict kept, answer_removed, picks_removed, rejected, or skipped:<why>
 * @property array<string, mixed>|null $trace counts and ids of what was sent and what came of it
 * @property float|null $cost_usd
 * @property int|null $duration_ms
 * @property string|null $failure why it failed, as code and the nightly review read it (AnswerFailure)
 * @property string|null $failure_team why it failed, as the team decided
 */
class AssistantSearchAsk extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'products' => 'array',
            'picks' => 'array',
            'picked' => 'array',
            'whatsapp_shown' => 'boolean',
            'whatsapp_clicked' => 'boolean',
            'trace' => 'array',
            'cost_usd' => 'float',
            'prompt_version' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<AssistantAnswer, $this> the saved answer the shopper was shown */
    public function answer(): BelongsTo
    {
        return $this->belongsTo(AssistantAnswer::class, 'answer_id');
    }
}
