<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a search that found nothing shows from now on.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $query normalized, as shoppers' searches are counted
 * @property string $query_hash
 * @property string $status resolved (shown), none (nothing in the shop fits), refused (the second model said no), rejected (the team took it back)
 * @property list<string> $products external ids, best first
 * @property list<string> $content external ids of guides and pages
 * @property string|null $synonym_means a word the shop uses that the query means, when the matcher found one
 * @property list<array<string, mixed>> $candidates what code offered the matcher
 * @property string $fingerprint the query and the candidates it was matched against
 * @property int $searches how many times the query had found nothing when it was resolved
 * @property string|null $matched_by
 * @property string|null $checked_by
 * @property string|null $reason
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
class SearchResolution extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const RESOLVED = 'resolved';

    public const NONE = 'none';

    public const REFUSED = 'refused';

    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'content' => 'array',
            'candidates' => 'array',
            'searches' => 'integer',
            'decided_at' => 'datetime',
        ];
    }
}
