<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One build of a store's goes-with map (BuildGoesWithMap): which of its own product types go with which, as one model
 * family wrote them from the list of types and the other scored them, with what code kept, what was taken out and why,
 * and what each call cost. The latest map that passed is what the assistant reads (GoesWithPartners); the rest are the
 * record.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $status
 * @property string $fingerprint
 * @property string|null $writer
 * @property string|null $checker
 * @property list<array{key: string, label: string, products: int, examples: list<string>}>|null $types
 * @property list<array{type: string, partner_type: string, why: string}>|null $proposed
 * @property list<array{type: string, partner_type: string, why: string, score: int}>|null $pairs
 * @property array<string, mixed>|null $review
 * @property list<array<string, mixed>>|null $removed
 * @property list<array<string, mixed>>|null $calls
 * @property list<string>|null $steps
 * @property list<string>|null $problems
 * @property string|null $run_id
 * @property Carbon $created_at
 */
class EnrichmentGoesWithMap extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** At least one pair kept: the map the assistant reads. */
    public const PASSED = 'passed';

    /** Written and checked, and no pair was kept. */
    public const HELD = 'held';

    /** The run stopped: a reply broke its schema, a cap was reached, the two models were of one family. */
    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'proposed' => 'array',
            'pairs' => 'array',
            'review' => 'array',
            'removed' => 'array',
            'calls' => 'array',
            'steps' => 'array',
            'problems' => 'array',
        ];
    }
}
