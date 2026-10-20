<?php

namespace App\Modules\Taxonomy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A product or post as it was when it was last tagged: what it said (input_hash) and the bank it
 * was tagged against, so only what changed is tagged again.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $input_hash
 * @property int $bank_version
 * @property int $dropped tags the model gave that code did not keep
 * @property string|null $run_id
 */
class TaxonomySubject extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['bank_version' => 'integer', 'dropped' => 'integer'];
    }
}
