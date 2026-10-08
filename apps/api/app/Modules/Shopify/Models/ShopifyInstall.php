<?php

namespace App\Modules\Shopify\Models;

use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Shopify store that installed the app. Not tenant-scoped: installs, webhooks and billing find
 * it by the store's myshopify domain before any shop is chosen.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $shop_domain
 * @property string|null $access_token
 * @property Carbon|null $access_expires_at
 * @property string|null $refresh_token
 * @property Carbon|null $refresh_expires_at
 * @property string|null $scopes
 * @property string|null $owner_email
 * @property Carbon|null $installed_at
 * @property Carbon|null $uninstalled_at
 * @property string|null $subscription_id
 * @property string $subscription_status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $subscribed_at
 */
class ShopifyInstall extends Model
{
    use HasUlids;

    /** The subscription lets the store use the app. */
    public const ACTIVE = 'active';

    protected $table = 'shopify_installs';

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'subscribed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function installed(): bool
    {
        return $this->uninstalled_at === null && $this->access_token !== null;
    }

    public function subscribed(): bool
    {
        return $this->installed() && $this->subscription_status === self::ACTIVE;
    }
}
