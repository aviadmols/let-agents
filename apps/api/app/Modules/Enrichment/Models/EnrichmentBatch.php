<?php

namespace App\Modules\Enrichment\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Enrichment\Enums\BatchStatus;
use App\Modules\Enrichment\Enums\TaskType;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $shop_id
 * @property TaskType $task
 * @property int $review_tier
 * @property string $prompt_key
 * @property int $prompt_version
 * @property string $prompt_hash
 * @property string $system_prompt
 * @property string|null $vocabulary_id
 * @property array<string, mixed>|null $scope
 * @property string $runner
 * @property BatchStatus $status
 * @property int $request_count
 * @property int $result_count
 * @property int $accepted_count
 * @property int $rejected_count
 * @property string|null $model
 * @property string|null $export_run_id
 * @property string|null $import_run_id
 * @property int|null $created_by
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 */
class EnrichmentBatch extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'task' => TaskType::class,
            'status' => BatchStatus::class,
            'scope' => 'array',
            'review_tier' => 'integer',
            'prompt_version' => 'integer',
            'request_count' => 'integer',
            'result_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<EnrichmentVocabulary, $this> */
    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(EnrichmentVocabulary::class, 'vocabulary_id');
    }

    /** @return HasMany<EnrichmentBatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(EnrichmentBatchItem::class, 'batch_id');
    }

    public function fileName(): string
    {
        return sprintf('let-agents-%s-%s-%s.jsonl', $this->task->value, $this->created_at->format('Ymd-His'), strtolower(substr($this->id, -6)));
    }
}
