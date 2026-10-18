<?php

namespace App\Modules\Analytics\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * An address a shop blocked: its shoppers' requests to search, the assistant and the beacons are
 * refused (RefuseBlockedAddresses). Read through a short cache, cleared on every change.
 *
 * @property int $id
 * @property string $shop_id
 * @property string $ip
 * @property int|null $blocked_by
 * @property Carbon $created_at
 */
class BlockedIp extends Model
{
    use BelongsToTenant;

    protected $table = 'analytics_blocked_ips';

    protected $guarded = ['id'];

    /** @return list<string> the shop's blocked addresses, cached a few minutes */
    public static function forShop(string $shopId): array
    {
        return Cache::remember(self::key($shopId), 300, fn (): array => static::query()->withoutGlobalScopes()->where('shop_id', $shopId)->pluck('ip')->all());
    }

    public static function forget(string $shopId): void
    {
        Cache::forget(self::key($shopId));
    }

    private static function key(string $shopId): string
    {
        return "analytics:blocked-ips:{$shopId}";
    }
}
