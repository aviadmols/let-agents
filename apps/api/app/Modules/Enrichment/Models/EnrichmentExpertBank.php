<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One dry run of the expert bank (BuildExpertBank): the bank a model wrote for this store from its own words, what code
 * measured of it, every score the checker gave, what was removed and why, and what each call cost. A report only: it is
 * no vocabulary, nothing reads products with it and no gate advances it.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $status
 * @property string $fingerprint
 * @property int $products
 * @property string|null $writer
 * @property string|null $checker
 * @property array<string, mixed>|null $facts
 * @property array{types: list<array<string, mixed>>, attributes: list<array<string, mixed>>}|null $bank
 * @property list<array<string, mixed>>|null $drafts
 * @property array<string, mixed>|null $measurement
 * @property array<string, mixed>|null $review
 * @property list<array<string, mixed>>|null $removed
 * @property array<string, mixed>|null $gate
 * @property list<array<string, mixed>>|null $calls
 * @property list<string>|null $steps
 * @property list<string>|null $problems
 * @property string|null $run_id
 * @property Carbon $created_at
 */
class EnrichmentExpertBank extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** The store's own numbers reach every threshold. Still a report: nothing is activated. */
    public const PASSED = 'passed';

    /** Written, measured and checked, and the numbers do not reach a threshold. */
    public const HELD = 'held';

    /** The run stopped: a reply broke its schema, a cap was reached, the two models were of one family. */
    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'products' => 'integer',
            'facts' => 'array',
            'bank' => 'array',
            'drafts' => 'array',
            'measurement' => 'array',
            'review' => 'array',
            'removed' => 'array',
            'gate' => 'array',
            'calls' => 'array',
            'steps' => 'array',
            'problems' => 'array',
        ];
    }
}
