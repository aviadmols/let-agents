<?php

namespace App\Modules\Demo\Models;

use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A store someone asked to see Lets on (see the demo_sites migration). Not a shop's own row: it is the platform's,
 * and its demo store is a shop of its own while it lasts.
 *
 * @property string $id
 * @property string $token
 * @property string $url
 * @property string $host
 * @property string|null $title
 * @property string $email
 * @property bool $consent
 * @property array{kind?: string, matters?: string}|null $choices
 * @property string $locale
 * @property string|null $code_hash
 * @property Carbon|null $code_expires_at
 * @property int $code_tries
 * @property Carbon|null $verified_at
 * @property string|null $shop_id
 * @property string|null $platform
 * @property string $status
 * @property string|null $error
 * @property int $products
 * @property int $pages
 * @property string|null $screenshot
 * @property string|null $screenshot_mobile
 * @property Carbon|null $ready_at
 * @property Carbon|null $emailed_at
 * @property Carbon|null $expires_at
 */
class DemoSite extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const SCANNING = 'scanning';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $guarded = [];

    protected $hidden = ['code_hash', 'screenshot', 'screenshot_mobile', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'consent' => 'boolean',
            'choices' => 'array',
            'code_tries' => 'integer',
            'products' => 'integer',
            'pages' => 'integer',
            'cost_usd' => 'float',
            'code_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'ready_at' => 'datetime',
            'emailed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function live(): bool
    {
        return in_array($this->status, [self::SCANNING, self::READY], true) && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
