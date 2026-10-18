<?php

namespace App\Modules\Analytics\Models;

use App\Core\Facades\Settings;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One visit to the store (a session of the search window's script): where it came from, on what,
 * and what happened in it. Its steps are AnalyticsVisitStep.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $session_id
 * @property string|null $visitor_hash
 * @property int $visit_number
 * @property Carbon $started_at
 * @property Carbon $last_at
 * @property string|null $landing
 * @property string|null $referrer
 * @property string $source
 * @property bool $paid
 * @property string|null $campaign
 * @property array<string, string>|null $params
 * @property string|null $device
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $ip
 * @property int $pages
 * @property int $steps
 * @property list<string>|null $did
 * @property list<string>|null $searches
 * @property list<string>|null $asks
 * @property string|null $order_total
 * @property bool $preview
 */
class AnalyticsVisit extends Model
{
    use BelongsToTenant;
    use MassPrunable;

    /** What a visit can have in it, as the sessions screen filters by. */
    public const DID = ['search', 'photo', 'ask', 'heart', 'cart', 'order', 'lead'];

    /** Where a visit came from. */
    public const SOURCES = ['google', 'facebook', 'instagram', 'whatsapp', 'email', 'direct', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_at' => 'datetime',
            'params' => 'array',
            'did' => 'array',
            'searches' => 'array',
            'asks' => 'array',
            'paid' => 'boolean',
            'preview' => 'boolean',
            'visit_number' => 'integer',
            'pages' => 'integer',
            'steps' => 'integer',
        ];
    }

    /** @return HasMany<AnalyticsVisitStep, $this> */
    public function stepsInOrder(): HasMany
    {
        return $this->hasMany(AnalyticsVisitStep::class, 'visit_id')->orderBy('at')->orderBy('id');
    }

    /** Seconds from the first step to the last. */
    public function duration(): int
    {
        return max(0, (int) $this->started_at->diffInSeconds($this->last_at, true));
    }

    public function has(string $what): bool
    {
        return in_array($what, (array) $this->did, true);
    }

    /** @return Builder<AnalyticsVisit> */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('last_at', '<', now()->subDays((int) Settings::get('analytics.sessions_keep_days')));
    }
}
