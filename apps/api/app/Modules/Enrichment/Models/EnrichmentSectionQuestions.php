<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The questions one section of a page or post answers, by the hash of its text. See
 * Tasks\SectionQuestionsTask (how they are written and checked) and Support\WrittenSectionQuestions.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $text_hash
 * @property int $prompt_version
 * @property list<string> $questions
 * @property string $model
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EnrichmentSectionQuestions extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'enrichment_section_questions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'prompt_version' => 'integer',
            'questions' => 'array',
        ];
    }
}
