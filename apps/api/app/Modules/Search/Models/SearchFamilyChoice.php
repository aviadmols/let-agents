<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One decision of a store's team about its word families (StoreFamilies): a family approved, fixed or made by hand, one
 * that is wrong, or a searched word set aside.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $status approved, rejected or hidden
 * @property list<string> $words
 * @property int|null $decided_by
 */
class SearchFamilyChoice extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const HIDDEN = 'hidden';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['words' => 'array'];
    }
}
