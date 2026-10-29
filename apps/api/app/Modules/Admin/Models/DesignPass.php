<?php

namespace App\Modules\Admin\Models;

use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A designer's pass into one shop's panel, for a day (CreateDesignPass). Only its token's hash is kept: the link is
 * shown once, when it is made.
 *
 * @property string $id
 * @property string $shop_id
 * @property int $user_id the shop's design account
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property int|null $created_by
 */
class DesignPass extends Model
{
    use HasUlids;

    /** What the session holds while a designer works: the pass, and the stylesheet from their computer. */
    public const SESSION = 'design_pass';

    public const SESSION_CSS = 'design_css';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function open(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A stylesheet the panel may load from the designer's own computer: http(s) on localhost or 127.0.0.1, any port,
     * a path ending in .css. Anything else is no one's to load into a shop's panel.
     */
    public static function localCss(string $url): ?string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true)
            || ! str_ends_with((string) ($parts['path'] ?? ''), '.css') || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}
