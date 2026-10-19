<?php

namespace App\Modules\Admin\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone an owner invited to the shop's panel. The link's token lives only in the email; the row keeps
 * its SHA-256. Sending again makes a new token, so the older link stops working.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $email
 * @property string|null $name
 * @property ShopRole $role
 * @property string $locale
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon|null $sent_at
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $user_id
 */
class ShopInvitation extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => ShopRole::class,
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @param Builder<ShopInvitation> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    public function expired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    public function matches(string $token): bool
    {
        return hash_equals($this->token_hash, self::hashOf($token));
    }
}
