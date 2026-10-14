<?php

namespace App\Modules\Runs\Models;

use App\Core\Facades\Settings;
use App\Modules\Runs\Support\LearningAgents;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One model call as it was: the prompt, what was sent, what came back. The ledger (ai_usage) is
 * the bill and is kept long; this is the evidence and is kept runs.call_log_days. Operator only.
 *
 * Not BelongsToTenant, for the same reason as Run: the operator reads it across shops.
 *
 * @property int $id
 * @property string|null $run_id
 * @property string|null $shop_id
 * @property string|null $agent
 * @property string|null $action
 * @property string $kind
 * @property string $provider
 * @property string $model
 * @property string|null $system
 * @property string $sent
 * @property string|null $received
 * @property string|null $error
 * @property bool $ok
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $duration_ms
 * @property Carbon $created_at
 */
class RunCall extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ok' => 'boolean'];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return Builder<RunCall> */
    public function prunable(): Builder
    {
        // The learning and checking agents' calls stay: they are what the system learned, and why
        // (LearningAgents). A call outside any run has no action, and NOT IN never matches a null.
        $kept = LearningAgents::actions();

        return static::query()->where('created_at', '<', now()->subDays((int) Settings::get('runs.call_log_days')))
            ->when($kept !== [], fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('action')->orWhereNotIn('action', $kept)));
    }
}
