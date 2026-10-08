<?php

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Str;

final class CreateShop
{
    /**
     * @param  array{name: string, domain: string, platform: string, slug?: string|null, content_locale?: string, currency?: string, timezone?: string}  $attributes
     */
    public function handle(array $attributes): Shop
    {
        $attributes['domain'] = self::normalizeDomain($attributes['domain']);
        $attributes['slug'] = filled($attributes['slug'] ?? null)
            ? Str::slug((string) $attributes['slug'])
            : $this->uniqueSlug($attributes['name'], $attributes['domain']);

        return Shop::query()->create($attributes);
    }

    /**
     * "https://www.Store.co.il/shop/" and "store.co.il" are the same shop.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $host = parse_url(str_contains($domain, '://') ? $domain : "https://{$domain}", PHP_URL_HOST) ?: $domain;

        return preg_replace('/^www\./', '', rtrim($host, '.')) ?? $host;
    }

    /**
     * Str::slug() drops Hebrew entirely, so "כלי עבודה" would become "". The domain's first
     * label ("demo-tools" for demo-tools.co.il) is the readable fallback.
     */
    private function uniqueSlug(string $name, string $domain): string
    {
        $base = Str::slug($name) ?: Str::slug(explode('.', $domain)[0]) ?: 'shop';
        $slug = $base;

        // The slug is the shop's web address too, so a platform name (www, api…) is never one.
        while (in_array($slug, Shop::RESERVED_SLUGS, true) || Shop::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
