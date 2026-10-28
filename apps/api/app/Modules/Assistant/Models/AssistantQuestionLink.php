<?php

namespace App\Modules\Assistant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What the store's team says answers a question: its categories (external ids), its tags (as the store names them) and
 * products by hand (external ids). The answer is chosen among them first (QuestionLinks).
 *
 * @property string $id
 * @property string $shop_id
 * @property string $question_key
 * @property string $question
 * @property list<string>|null $categories
 * @property list<string>|null $tags
 * @property list<string>|null $products
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AssistantQuestionLink extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['categories' => 'array', 'tags' => 'array', 'products' => 'array'];
    }

    /** Whether it links to anything at all. */
    public function empty(): bool
    {
        return (array) $this->categories === [] && (array) $this->tags === [] && (array) $this->products === [];
    }
}
