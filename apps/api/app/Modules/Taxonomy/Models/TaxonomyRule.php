<?php

namespace App\Modules\Taxonomy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A rule the shop wrote of its products, in its own words, and what was made of it.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $text as written, up to 600 characters
 * @property string $status reading | proposed | unclear | applied | rejected | failed
 * @property string|null $note what was understood, or the question asked back when the rule was unclear
 * @property array<string, mixed>|null $plan the changes, a product each, as shown to the team (ReadRule)
 * @property string|null $fingerprint the rule and the products it was read against: the same input is never read twice
 * @property string|null $proposed_by
 * @property string|null $audited_by
 * @property string|null $run_id
 * @property int|null $created_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TaxonomyRule extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const READING = 'reading';

    public const PROPOSED = 'proposed';

    public const UNCLEAR = 'unclear';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    public const MAX_TEXT = 600;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['plan' => 'array', 'decided_at' => 'datetime'];
    }
}
