<?php

namespace App\Modules\Connections\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * How Let Agents reaches one store: the site address and the token its Let Agents plugin issued.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $platform
 * @property string $site_url
 * @property string $access_token decrypted on read
 * @property string|null $token_prefix
 * @property ConnectionStatus $status
 * @property string|null $last_error_code
 * @property Carbon|null $last_checked_at
 * @property array<string, mixed>|null $site_info the plugin's /status response
 * @property string|null $site_key public id the storefront widget sends, derived from the token
 */
class StoreConnection extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['shop_id', 'platform', 'site_url', 'access_token'];

    protected $hidden = ['access_token'];

    protected $attributes = [
        'platform' => 'woocommerce',
        'status' => 'untested',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'status' => ConnectionStatus::class,
            'last_checked_at' => 'datetime',
            'site_info' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StoreConnection $connection): void {
            $connection->site_url = rtrim(trim($connection->site_url), '/');

            if ($connection->isDirty('access_token')) {
                $connection->token_prefix = mb_substr((string) $connection->access_token, 0, 10);
                $connection->site_key = SiteKeys::site((string) $connection->access_token);
                $connection->status = ConnectionStatus::Untested;
                $connection->last_error_code = null;
            }
        });
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** The connection a storefront site key belongs to, across all shops. */
    public static function forSite(string $siteKey): ?self
    {
        if (! preg_match('/^[a-f0-9]{24}$/', $siteKey)) {
            return null;
        }

        return app(TenantContext::class)->runUnscoped(fn () => static::query()->with('shop')->where('site_key', $siteKey)->first());
    }

    /** Private key for preview links: the widget shows only to whoever opens such a link. */
    public function previewKey(): string
    {
        return SiteKeys::preview((string) $this->access_token);
    }

    /** Whether a request really came from this store's plugin. */
    public function verifyPluginSignature(string $timestamp, string $method, string $path, string $body, string $signature): bool
    {
        return SiteKeys::verify((string) $this->access_token, $timestamp, $method, $path, $body, $signature);
    }

    /** Hosts the storefront widget may run on: the connected site and the shop's domain. */
    public function allowedHosts(): array
    {
        $hosts = [parse_url($this->site_url, PHP_URL_HOST), $this->shop?->domain];

        return array_values(array_unique(array_filter(array_map(fn ($h) => is_string($h) ? strtolower(preg_replace('/^www\./', '', $h)) : null, $hosts))));
    }

    /** Whether a browser Origin header belongs to one of the allowed hosts. No header is not a browser. */
    public function allowsOrigin(?string $origin): bool
    {
        $host = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : null;

        return is_string($host) && in_array(strtolower((string) preg_replace('/^www\./', '', $host)), $this->allowedHosts(), true);
    }

    /** A value from the last status response, e.g. "woocommerce.version". */
    public function info(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->site_info ?? [], $path, $default);
    }
}
