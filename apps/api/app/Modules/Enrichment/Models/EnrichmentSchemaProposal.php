<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shop's vocabulary, discovered from its products (see DiscoverSchema and ProposeSchema), and
 * the operator's decision on it.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $status
 * @property int $sampled
 * @property string|null $template
 * @property array<string, mixed> $stats
 * @property array<string, mixed>|null $proposed a vocabulary definition
 * @property array<string, mixed>|null $coverage
 * @property array<string, mixed>|null $review
 * @property list<string>|null $problems
 * @property string|null $summary
 * @property string $fingerprint
 * @property string|null $run_id
 * @property string|null $vocabulary_id
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
class EnrichmentSchemaProposal extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Counted in code; no model has written a vocabulary yet. */
    public const DISCOVERED = 'discovered';

    /** Written, measured and reviewed; waiting for the operator. */
    public const PROPOSED = 'proposed';

    /** The operator approved it: it is a version of the shop's vocabulary. */
    public const APPROVED = 'approved';

    /** The operator said no. */
    public const REJECTED = 'rejected';

    /** The proposal could not be written or did not hold together. */
    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'proposed' => 'array',
            'coverage' => 'array',
            'review' => 'array',
            'problems' => 'array',
            'sampled' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(EnrichmentVocabulary::class, 'vocabulary_id');
    }
}
