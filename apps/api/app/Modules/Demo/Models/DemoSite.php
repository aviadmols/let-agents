<?php

namespace App\Modules\Demo\Models;

use App\Core\Facades\Features;
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
 * @property array{kind?: string, matters?: string, language?: string}|null $choices
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
 * @property Carbon|null $read_at
 * @property Carbon|null $scan_at
 * @property Carbon|null $shot_at
 * @property int $attempts
 * @property int $retries
 * @property int|null $store_products
 * @property int $pictures
 */
class DemoSite extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const SCANNING = 'scanning';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** The language of the demo's pages and emails; its search speaks the store's (choices.language). */
    public const LOCALE = 'en';

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
            'read_at' => 'datetime',
            'scan_at' => 'datetime',
            'shot_at' => 'datetime',
            'attempts' => 'integer',
            'retries' => 'integer',
            'store_products' => 'integer',
            'pictures' => 'integer',
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

    /**
     * Whether the demo reads its products' pictures: every demo does while demo.pictures is on, so a search by how a
     * product looks finds it whatever matters most to whoever asked (that sets how alike and photo search compare).
     */
    public function readsPictures(): bool
    {
        return Features::enabled('demo.pictures');
    }
}
