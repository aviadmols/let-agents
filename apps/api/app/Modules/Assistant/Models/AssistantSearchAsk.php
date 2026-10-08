<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
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
 * @property list<array{external_id: string, title: string, why: string}>|null $picks
 * @property bool $whatsapp_shown
 * @property bool $whatsapp_clicked
 * @property list<string>|null $picked external ids of picked products the shopper opened or added
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
        ];
    }
}
