<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the store's rules for its answers, in its own words: how to answer, never a fact (AnswerRules).
 *
 * @property string $id
 * @property string $shop_id
 * @property string $text
 * @property int|null $created_by
 */
class AssistantAnswerRule extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];
}
