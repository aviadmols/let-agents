<?php

namespace App\Modules\Shoppers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A code sent to a phone or an email, waiting to be typed back.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $visitor_hash
 * @property string $channel
 * @property string $contact_hash
 * @property string $contact encrypted at rest
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class ShopperVerification extends Model
{
    use BelongsToTenant;
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'contact' => 'encrypted',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Codes a day past their time, every shop's: model:prune runs with no shop in the tenant context, so the scope that
     * keeps one shop's rows from another's is left off here (as AnalyticsEvent prunes). With it on, the nightly prune
     * failed every night and no expired code was ever removed (9-11.11).
     *
     * @return Builder<$this>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('expires_at', '<', now()->subDay());
    }
}
