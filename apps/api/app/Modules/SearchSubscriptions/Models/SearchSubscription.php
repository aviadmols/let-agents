<?php

namespace App\Modules\SearchSubscriptions\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A shopper's subscription to one search in one store (Search Subscriptions). Pending until confirmed from the email;
 * then active until stopped (one tap in an email, unkept after its days, unopened emails, an erasure, the store left).
 *
 * @property string $id
 * @property string $shop_id
 * @property string $email encrypted at rest
 * @property string $email_hash keyed hash of the address, the same in every store (Subscribers::hash)
 * @property string $query as the shopper typed it
 * @property string $query_key as code reads it
 * @property string $locale the drawer's language when they subscribed
 * @property string $status pending, active or stopped
 * @property string|null $stop_reason one, all, unopened, expired, erased, removed
 * @property string $consent_text the words they agreed to
 * @property Carbon $consent_at
 * @property string|null $consent_ip_hash
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $stopped_at
 * @property Carbon|null $confirm_sent_at
 * @property Carbon $created_at
 */
class SearchSubscription extends Model
{
    use BelongsToTenant, HasUlids;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const STOPPED = 'stopped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'consent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
            'reminded_at' => 'datetime',
            'stopped_at' => 'datetime',
            'confirm_sent_at' => 'datetime',
        ];
    }

    public function stop(string $reason): void
    {
        if ($this->status !== self::STOPPED) {
            $this->forceFill(['status' => self::STOPPED, 'stop_reason' => $reason, 'stopped_at' => now()])->save();
        }
    }
}
