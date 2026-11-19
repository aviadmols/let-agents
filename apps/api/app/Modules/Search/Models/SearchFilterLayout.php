<?php

namespace App\Modules\Search\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One arrangement of a store's filter screen (ArrangeFilters): the screen code read, the proposal, every score the
 * checker gave, what code applied and refused, and what each call cost. The newest row that did not fail is the one
 * the search index applies (FilterLayouts); `search layout {shop} --clear` removes them all.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $status
 * @property string $fingerprint
 * @property string|null $writer
 * @property string|null $checker
 * @property array<string, mixed>|null $screen
 * @property array<string, mixed>|null $proposal
 * @property list<array<string, mixed>>|null $items
 * @property array<string, mixed>|null $review
 * @property array{order: list<string>, values: array<string, list<array{0: string, 1: string}>>, hide: array<string, string>, moves: array<string, list<array{0: string, 1: string, 2: string, 3: string}>>}|null $applied
 * @property array{ignored: list<array<string, string>>, refused: list<array{id: string, why: string}>}|null $refused
 * @property list<array<string, mixed>>|null $calls
 * @property list<string>|null $steps
 * @property list<string>|null $problems
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property string|null $run_id
 * @property Carbon $created_at
 */
class SearchFilterLayout extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Code applied at least one change the checker let through. */
    public const APPLIED = 'applied';

    /** Nothing passed: the screen stays as code built it. */
    public const KEPT = 'kept';

    /** The run stopped: a reply broke its schema, a cap was reached, a model failed. Never read by the index. */
    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'screen' => 'array',
            'proposal' => 'array',
            'items' => 'array',
            'review' => 'array',
            'applied' => 'array',
            'refused' => 'array',
            'calls' => 'array',
            'steps' => 'array',
            'problems' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:4',
        ];
    }
}
