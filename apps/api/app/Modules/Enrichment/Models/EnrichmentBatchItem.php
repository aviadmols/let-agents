<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Enrichment\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request in a batch: what the model received, what code needs to check its answer, and
 * the answer once it arrives.
 *
 * @property string $id
 * @property string $batch_id
 * @property string $shop_id
 * @property string $custom_id
 * @property string $subject_type product, content or section (a section of a page or post)
 * @property string $subject_id
 * @property string $input_hash
 * @property array<string, mixed> $request
 * @property array<string, mixed> $context
 * @property ItemStatus $status
 * @property array<string, mixed>|null $result
 * @property list<string>|null $problems
 */
class EnrichmentBatchItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'context' => 'array',
            'result' => 'array',
            'problems' => 'array',
            'status' => ItemStatus::class,
        ];
    }

    /** @return BelongsTo<EnrichmentBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(EnrichmentBatch::class, 'batch_id');
    }
}
