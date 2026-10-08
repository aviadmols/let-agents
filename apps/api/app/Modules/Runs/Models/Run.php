<?php

namespace App\Modules\Runs\Models;

use App\Core\Facades\Settings;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * One thing an agent did: who, for which shop, with what input, what came out, how long it
 * took, and what it cost.
 *
 * Not BelongsToTenant: some runs belong to no shop (checking an AI provider key), and the
 * operator reads runs across every shop. Merchant screens filter with forShop().
 *
 * @property string $id
 * @property string|null $shop_id
 * @property string $agent
 * @property string $action
 * @property RunStatus $status
 * @property RunTrigger $trigger
 * @property string|null $summary_key
 * @property array<string, scalar>|null $summary_params
 * @property string|null $error
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $provider
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property string|null $cost_usd
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 */
class Run extends Model
{
    use HasUlids;
    use MassPrunable;

    private const SECRET_KEY_PATTERN = '/token|secret|password|api[_-]?key|authorization|credential/i';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'trigger' => RunTrigger::class,
            'summary_params' => 'array',
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * The configured auth model, so this module does not depend on the Admin module.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    /** @return BelongsTo<Run, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @param Builder<Run> $query */
    public function scopeForShop(Builder $query, string $shopId): void
    {
        $query->where('shop_id', $shopId);
    }

    /** @return Builder<Run> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) Settings::get('runs.retention_days')));
    }

    public function summary(): ?string
    {
        if ($this->summary_key === null) {
            return null;
        }

        return __($this->summary_key, $this->summary_params ?? []);
    }

    public function agentLabel(): string
    {
        return self::labelFor('agents', $this->agent);
    }

    public function actionLabel(): string
    {
        return self::labelFor('actions', $this->action);
    }

    public function durationForHumans(): ?string
    {
        if ($this->duration_ms === null) {
            return null;
        }

        return $this->duration_ms < 1000
            ? __('runs::runs.duration.ms', ['value' => $this->duration_ms])
            : __('runs::runs.duration.seconds', ['value' => number_format($this->duration_ms / 1000, 1)]);
    }

    /**
     * "connections.store_checker" reads its label from connections::agents.store_checker, so
     * each module names its own agents in its own translations.
     */
    /**
     * One model call in the spend ledger. Runs write theirs when they end; work that is too small
     * for a run of its own (a search query's embedding, a shopper's photo) writes here directly.
     */
    public static function recordUsage(?string $shopId, string $agent, string $action, string $provider, string $model, int $inputTokens, int $outputTokens, float $costUsd, ?string $runId = null, int $cacheReadTokens = 0): void
    {
        DB::table('ai_usage')->insert([
            'run_id' => $runId,
            'shop_id' => $shopId,
            'agent' => $agent,
            'action' => $action,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => max(0, $inputTokens),
            'output_tokens' => max(0, $outputTokens),
            'cache_read_tokens' => max(0, $cacheReadTokens),
            'cost_usd' => $costUsd,
            'day' => now()->toDateString(),
            'created_at' => now(),
        ]);
    }

    public static function labelFor(string $group, string $identifier): string
    {
        [$module, $name] = array_pad(explode('.', $identifier, 2), 2, '');
        $key = "{$module}::{$group}.{$name}";

        return $name !== '' && Lang::has($key) ? __($key) : $identifier;
    }

    /**
     * Replaces the value of any key that looks like a credential, at any depth. Runs are read by
     * people and kept for months; secrets never belong in them.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
